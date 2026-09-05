<?php

namespace App\Services\Imports;

use App\DTOs\CreateContractDTO;
use App\DTOs\CreateTransactionDTO;
use App\Enums\DocumentType;
use App\Enums\LotStatus;
use App\Enums\LotType;
use App\Enums\TransactionType;
use App\Imports\SanMiguel\SanMiguelCustomSchedules;
use App\Imports\SanMiguel\SanMiguelHistoricalAlignments;
use App\Imports\SanMiguel\SanMiguelParsedClient;
use App\Imports\SanMiguel\SanMiguelParsedLot;
use App\Imports\SanMiguel\SanMiguelParsedPayment;
use App\Imports\SanMiguel\SanMiguelWorkbookParser;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Services\Sales\ContractService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SanMiguelImportService
{
    public function __construct(
        private readonly SanMiguelWorkbookParser $parser,
        private readonly ContractService $contractService,
        private readonly DownPaymentService $downPaymentService,
        private readonly CascadeCollectionService $cascadeCollectionService,
        private readonly SanMiguelWipeService $wipeService,
        private readonly SanMiguelHistoricalFinalizeService $historicalFinalizeService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(string $path, bool $dryRun, ?string $soloLote = null, bool $fresh = false): array
    {
        $freshResult = null;
        if (! $dryRun && $fresh) {
            $freshResult = $this->wipeService->run();
        }

        $lots = $this->parser->parse($path);
        if ($soloLote !== null && $soloLote !== '') {
            $lots = array_values(array_filter(
                $lots,
                fn ($lot) => $lot->lotNumber === $soloLote,
            ));
        }
        $project = $this->findProject();

        $customerIndex = $this->buildCustomerIndex();
        $stats = [
            'dry_run' => $dryRun,
            'file' => $path,
            'project' => $project?->name,
            'project_missing' => $project === null,
            'sheets' => count($lots),
            'variable' => 0,
            'especial' => 0,
            'customers_would_create' => 0,
            'customers_would_reuse' => 0,
            'payments' => 0,
            'lots_ok' => 0,
            'lots_with_issues' => 0,
            'imported' => 0,
            'failed' => [],
            'lot_details' => [],
            'inconsistencies' => [],
            'fresh' => $freshResult,
            'historical' => null,
            'official_workbook' => SanMiguelHistoricalAlignments::isOfficialWorkbook($path),
        ];

        $plannedCustomers = [];

        foreach ($lots as $lot) {
            if ($lot->kind === 'especial') {
                $stats['especial']++;
            } else {
                $stats['variable']++;
            }
            $stats['payments'] += count($lot->payments);

            $customerPlan = [];
            foreach ($lot->clients as $client) {
                $resolution = $this->resolveCustomerPlan($client, $customerIndex, $plannedCustomers);
                $customerPlan[] = $resolution;
                if ($resolution['action'] === 'create') {
                    $stats['customers_would_create']++;
                    $plannedCustomers[$resolution['key']] = true;
                } else {
                    $stats['customers_would_reuse']++;
                }
            }

            $lotRecord = $project ? $this->findLot($project, $lot->lotNumber) : null;
            $existingContract = $lotRecord
                ? Contract::query()->where('lot_id', $lotRecord->id)->first()
                : null;

            $issues = $lot->issues;
            if ($project === null) {
                $issues[] = 'No existe un proyecto cuyo nombre contenga "San Miguel".';
            }
            if ($existingContract) {
                $issues[] = sprintf(
                    'El lote %s ya tiene contrato %s; la importación real fallará para este lote.',
                    $lot->lotNumber,
                    $existingContract->contract_number,
                );
            }
            if ($lotRecord && $lotRecord->status !== LotStatus::DISPONIBLE && ! $existingContract) {
                $issues[] = sprintf(
                    'El lote %s existe con estado %s (se requiere disponible).',
                    $lot->lotNumber,
                    $lotRecord->status->value ?? (string) $lotRecord->status,
                );
            }

            if ($issues !== []) {
                $stats['lots_with_issues']++;
                foreach ($issues as $issue) {
                    $stats['inconsistencies'][] = "{$lot->sheetName}: {$issue}";
                }
            } else {
                $stats['lots_ok']++;
            }

            $stats['lot_details'][] = [
                'sheet' => $lot->sheetName,
                'kind' => $lot->kind,
                'lot_number' => $lot->lotNumber,
                'sale_price' => $lot->salePrice,
                'down_payment' => $lot->downPaymentPactada,
                'interest_rate' => $lot->interestRate,
                'term_months' => $lot->termMonths,
                'clients' => $customerPlan,
                'payments' => count($lot->payments),
                'sum_payments' => $lot->sumPayments,
                'last_excel_saldo' => $lot->lastExcelSaldo,
                'expected_saldo' => $lot->expectedSaldo,
                'saldo_matches' => $lot->saldoMatches,
                'start_date' => isset($lot->payments[0]) ? $lot->payments[0]->date->toDateString() : null,
                'issues' => $issues,
            ];
        }

        if ($dryRun) {
            $stats['historical'] = [
                'skipped' => true,
                'reason' => $stats['official_workbook']
                    ? 'dry-run: las fases 2-4 no se ejecutan'
                    : 'libro no oficial: las fases 2-4 no se ejecutan',
            ];

            return $stats;
        }

        if ($project === null) {
            $project = Project::query()->create([
                'name' => 'Proyecto San Miguel',
                'description' => 'Creado por import:san-miguel',
                'location' => 'San Miguel',
                'status' => 'active',
                'created_by' => $this->actorId(),
            ]);
            $stats['project'] = $project->name;
            $stats['project_created'] = true;
        }

        foreach ($lots as $lot) {
            try {
                DB::transaction(function () use ($lot, $project, &$stats) {
                    $this->importLot($lot, $project);
                    $stats['imported']++;
                });
            } catch (\Throwable $e) {
                $stats['failed'][] = [
                    'sheet' => $lot->sheetName,
                    'reason' => $this->formatException($e),
                ];
            }
        }

        if (SanMiguelHistoricalAlignments::isOfficialWorkbook($path) && $stats['failed'] === []) {
            $stats['historical'] = $this->historicalFinalizeService->run($path, $soloLote);
        } elseif (SanMiguelHistoricalAlignments::isOfficialWorkbook($path)) {
            $stats['historical'] = [
                'skipped' => true,
                'reason' => 'hay lotes fallidos en la fase 1; no se corren las fases 2-4',
            ];
        } else {
            $stats['historical'] = [
                'skipped' => true,
                'reason' => 'libro no oficial: las fases 2-4 no se ejecutan',
            ];
        }

        return $stats;
    }

    private function importLot(SanMiguelParsedLot $lot, Project $project): void
    {
        $holderIds = [];
        foreach ($lot->clients as $client) {
            $holderIds[] = $this->persistCustomer($client)->id;
        }
        if ($holderIds === []) {
            throw ValidationException::withMessages([
                'customers' => 'El lote no tiene titulares.',
            ]);
        }

        $isCustomLot = SanMiguelCustomSchedules::isCustomLot($lot->lotNumber);
        $salePrice = $isCustomLot
            ? SanMiguelCustomSchedules::salePrice($lot->lotNumber)
            : $lot->salePrice;

        $lotModel = $this->findLot($project, $lot->lotNumber);
        if (! $lotModel) {
            $lotModel = Lot::query()->create([
                'project_id' => $project->id,
                'number' => $lot->lotNumber,
                'area_m2' => 0,
                'price_m2' => 0,
                'list_price' => $salePrice,
                'status' => LotStatus::DISPONIBLE->value,
                'type' => LotType::RESIDENTIAL->value,
                'created_by' => $this->actorId(),
            ]);
        }

        $firstPayment = $lot->payments[0]->date ?? Carbon::now()->startOfDay();
        if ($isCustomLot) {
            $start = Carbon::parse(SanMiguelCustomSchedules::CELEBRATION_DATE)->startOfDay();
            $firstInstallment = Carbon::parse(SanMiguelCustomSchedules::FIRST_INSTALLMENT_DATE)->startOfDay();
        } elseif ($lot->isSpecialLot) {
            $start = $firstPayment->copy();
            $firstInstallment = $start->copy();
        } elseif ($lot->firstNperDate) {
            $firstInstallment = $lot->firstNperDate->copy()->startOfDay();
            $start = $firstInstallment->copy()->subMonth();
        } else {
            $start = $firstPayment->copy();
            $firstInstallment = $start->copy()->addMonth();
        }

        $anchorId = $holderIds[0];
        $coTitularIds = array_values(array_filter($holderIds, fn (int $id) => $id !== $anchorId));

        $contract = $this->contractService->createContract(new CreateContractDTO(
            contractNumber: 'SM-LOTE-'.$lot->lotNumber,
            customerId: $anchorId,
            lotId: $lotModel->id,
            sellerName: 'Importación histórica San Miguel',
            salePrice: (float) $salePrice,
            downPaymentPactada: (float) $lot->downPaymentPactada,
            termMonths: $lot->termMonths,
            interestRate: $lot->interestRate,
            startDate: $start->toDateString(),
            initialPaymentDate: $start->toDateString(),
            firstInstallmentDate: $firstInstallment->toDateString(),
            regularPaymentStartDate: $firstInstallment->toDateString(),
            preventaInstallmentsCount: 0,
            isCustomPlan: $isCustomLot,
            isSpecialLot: $lot->isSpecialLot,
            promises: $isCustomLot ? SanMiguelCustomSchedules::promises($lot->lotNumber) : null,
            createdBy: $this->actorId(),
            coTitularIds: $coTitularIds,
        ));

        foreach ($lot->payments as $payment) {
            Carbon::setTestNow($payment->date->copy()->endOfDay());
            try {
                $this->applyPayment($contract, $payment);
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    private function applyPayment(Contract $contract, SanMiguelParsedPayment $payment): void
    {
        $notes = $this->paymentNotes($payment);

        if ($payment->isDownPayment) {
            $this->applyDownPayment($contract, $payment, $notes);

            return;
        }

        $this->applyCascadePayment($contract, $payment, $payment->amount, $notes);
    }

    private function applyDownPayment(Contract $contract, SanMiguelParsedPayment $payment, ?string $notes): void
    {
        $contract->refresh();
        $totalPaid = $contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT)
            ->sum('amount');
        $pending = bcsub((string) $contract->down_payment_pactada, (string) $totalPaid, 2);

        if (bccomp($pending, '500.00', 2) < 0) {
            $this->applyCascadePayment($contract, $payment, $payment->amount, $notes);

            return;
        }

        $toInicial = bccomp($payment->amount, $pending, 2) === 1 ? $pending : $payment->amount;
        $remainder = bcsub($payment->amount, $toInicial, 2);

        $this->downPaymentService->registerDownPayment(new CreateTransactionDTO(
            contractId: $contract->id,
            amount: $toInicial,
            transactionDate: $payment->date,
            paymentMethod: $payment->paymentMethod,
            transactionType: TransactionType::DOWN_PAYMENT,
            installmentNumbers: [],
            notes: $notes,
        ));

        if (bccomp($remainder, '0.00', 2) === 1) {
            $this->applyCascadePayment($contract, $payment, $remainder, $notes);
        }
    }

    private function applyCascadePayment(
        Contract $contract,
        SanMiguelParsedPayment $payment,
        string $amount,
        ?string $notes,
    ): void {
        try {
            $this->cascadeCollectionService->process(
                $contract->id,
                $amount,
                $payment->collectionOption,
                $payment->date,
                [],
                null,
                $payment->paymentMethod,
                $notes,
            );
        } catch (ValidationException $e) {
            if (! $this->isObligationFulfilled($e)) {
                throw $e;
            }

            $this->recordUnappliedHistoricalPayment($contract, $payment, $amount, $notes);
        }
    }

    private function isObligationFulfilled(ValidationException $e): bool
    {
        foreach ($e->errors()['amount'] ?? [] as $message) {
            if (str_contains((string) $message, 'obligación ya fue cumplida')) {
                return true;
            }
        }

        return false;
    }

    private function recordUnappliedHistoricalPayment(
        Contract $contract,
        SanMiguelParsedPayment $payment,
        string $amount,
        ?string $notes,
    ): void {
        $suffix = 'Pago histórico registrado sin aplicar a cuotas: la obligación ya estaba cumplida.';
        $combined = $notes ? $notes.' | '.$suffix : $suffix;

        Transaction::query()->create([
            'contract_id' => $contract->id,
            'transaction_type' => TransactionType::REGULAR_PAYMENT,
            'amount' => $amount,
            'transaction_date' => $payment->date->toDateString(),
            'payment_method' => $payment->paymentMethod,
            'notes' => $combined,
        ]);
    }

    private function paymentNotes(SanMiguelParsedPayment $payment): ?string
    {
        $parts = [];
        if ($payment->receiptNumber) {
            $parts[] = 'Recibo #'.$payment->receiptNumber;
        }
        $parts[] = 'Concepto: '.$payment->concept;
        if ($payment->observation) {
            $parts[] = $payment->observation;
        }

        $text = implode(' | ', $parts);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, true>  $plannedCustomers
     * @return array<string, mixed>
     */
    private function resolveCustomerPlan(
        SanMiguelParsedClient $client,
        array $customerIndex,
        array $plannedCustomers,
    ): array {
        $key = $this->customerKey($client);
        $existing = $this->matchExistingCustomer($client, $customerIndex);

        if ($existing) {
            return [
                'action' => 'reuse',
                'key' => $key,
                'name' => $client->name,
                'document' => $existing->document_number,
                'existing_id' => $existing->id,
            ];
        }

        if (isset($plannedCustomers[$key])) {
            return [
                'action' => 'reuse',
                'key' => $key,
                'name' => $client->name,
                'document' => $client->documentNumber ?? $this->placeholderDocument($client),
                'existing_id' => null,
            ];
        }

        return [
            'action' => 'create',
            'key' => $key,
            'name' => $client->name,
            'document' => $client->documentNumber ?? $this->placeholderDocument($client),
            'existing_id' => null,
            'document_missing' => $client->documentMissing,
        ];
    }

    private function persistCustomer(SanMiguelParsedClient $client): Customer
    {
        if ($client->documentNumber) {
            $found = Customer::query()
                ->where('document_number', $client->documentNumber)
                ->first();
            if ($found) {
                return $found;
            }
        } else {
            $placeholder = $this->placeholderDocument($client);
            $found = Customer::query()->where('document_number', $placeholder)->first()
                ?? Customer::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($client->name)])
                    ->first();
            if ($found) {
                return $found;
            }
        }

        return Customer::query()->create([
            'document_type' => $client->documentType->value ?? DocumentType::CC->value,
            'document_number' => $client->documentNumber ?? $this->placeholderDocument($client),
            'name' => $client->name,
            'phone' => '0000000000',
            'created_by' => $this->actorId(),
        ]);
    }

    private function placeholderDocument(SanMiguelParsedClient $client): string
    {
        $slug = Str::upper(Str::slug($client->name, ''));
        $slug = substr($slug !== '' ? $slug : 'SINNOMBRE', 0, 32);

        return 'SM-NODNI-'.$slug;
    }

    private function customerKey(SanMiguelParsedClient $client): string
    {
        if ($client->documentNumber) {
            return 'doc:'.$client->documentNumber;
        }

        return 'name:'.mb_strtolower(trim($client->name));
    }

    /**
     * @return array<string, Customer>
     */
    private function buildCustomerIndex(): array
    {
        $index = [];
        foreach (Customer::query()->get() as $customer) {
            $index['doc:'.$customer->document_number] = $customer;
            $index['name:'.mb_strtolower(trim($customer->name))] = $customer;
        }

        return $index;
    }

    /**
     * @param  array<string, Customer>  $index
     */
    private function matchExistingCustomer(SanMiguelParsedClient $client, array $index): ?Customer
    {
        if ($client->documentNumber && isset($index['doc:'.$client->documentNumber])) {
            return $index['doc:'.$client->documentNumber];
        }

        if ($client->documentMissing && isset($index['name:'.mb_strtolower(trim($client->name))])) {
            return $index['name:'.mb_strtolower(trim($client->name))];
        }

        return null;
    }

    private function findProject(): ?Project
    {
        return Project::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%san miguel%'])
            ->orderBy('id')
            ->first();
    }

    private function findLot(Project $project, string $lotNumber): ?Lot
    {
        $candidates = array_unique([$lotNumber, ltrim($lotNumber, '0') ?: '0', 'LOTE '.$lotNumber]);

        return Lot::query()
            ->where('project_id', $project->id)
            ->whereIn('number', $candidates)
            ->first();
    }

    private function actorId(): ?int
    {
        return User::query()->orderBy('id')->value('id');
    }

    private function formatException(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $messages = collect($e->errors())->flatten()->implode(' ');

            return $messages !== '' ? $messages : $e->getMessage();
        }

        return $e->getMessage();
    }
}
