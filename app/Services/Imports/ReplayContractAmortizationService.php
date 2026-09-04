<?php

namespace App\Services\Imports;

use App\DTOs\CreateTransactionDTO;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplayContractAmortizationService
{
    /**
     * Pagos a reaplicar con reducir_plazo (fecha + monto).
     *
     * @var array<string, list<array{date: string, amount: string}>>
     */
    public const CORRECTIONS = [
        '4' => [
            ['date' => '2025-05-02', 'amount' => '13000000.00'],
        ],
        '22' => [
            ['date' => '2025-04-24', 'amount' => '5403979.00'],
            ['date' => '2025-09-22', 'amount' => '11260374.00'],
            ['date' => '2025-12-02', 'amount' => '5460000.00'],
            ['date' => '2026-02-07', 'amount' => '3965000.00'],
        ],
        '41' => [
            ['date' => '2026-04-16', 'amount' => '8396211.00'],
            ['date' => '2026-05-21', 'amount' => '8400000.00'],
            ['date' => '2026-06-22', 'amount' => '8400000.00'],
            ['date' => '2026-07-31', 'amount' => '8400000.00'],
        ],
        '49' => [
            ['date' => '2026-08-05', 'amount' => '7445500.00'],
        ],
        '55' => [
            ['date' => '2025-09-17', 'amount' => '4492400.00'],
        ],
    ];

    public function __construct(
        private readonly AmortizationService $amortizationService,
        private readonly DownPaymentService $downPaymentService,
        private readonly CascadeCollectionService $cascadeCollectionService,
    ) {}

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>, reapplied: int, warnings: list<string>}
     */
    public function preview(string $lotNumber): array
    {
        $contract = $this->contractForLot($lotNumber);
        $before = $this->snapshot($contract);

        $after = null;
        $reapplied = 0;
        $warnings = [];

        DB::beginTransaction();
        try {
            $result = $this->replay($contract, $lotNumber);
            $reapplied = $result['reapplied'];
            $warnings = $result['warnings'];
            $after = $this->snapshot($contract->fresh());
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        return [
            'before' => $before,
            'after' => $after,
            'reapplied' => $reapplied,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>, reapplied: int, warnings: list<string>}
     */
    public function apply(string $lotNumber): array
    {
        $contract = $this->contractForLot($lotNumber);
        $before = $this->snapshot($contract);

        $result = DB::transaction(function () use ($contract, $lotNumber) {
            return $this->replay($contract, $lotNumber);
        });
        Carbon::setTestNow();

        return [
            'before' => $before,
            'after' => $this->snapshot($contract->fresh()),
            'reapplied' => $result['reapplied'],
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * @return array{reapplied: int, warnings: list<string>}
     */
    private function replay(Contract $contract, string $lotNumber): array
    {
        $warnings = [];
        $reapplied = 0;
        $targets = self::CORRECTIONS[$lotNumber] ?? [];

        $contract->amortizationInstallments()->delete();
        $this->amortizationService->generateInitialProjection($contract->fresh());
        $contract->refresh();

        $transactions = $contract->transactions()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            Carbon::setTestNow($transaction->transaction_date->copy()->endOfDay());
            $amount = bcadd((string) $transaction->amount, '0', 2);
            $date = $transaction->transaction_date->copy()->startOfDay();
            $type = $transaction->transaction_type instanceof TransactionType
                ? $transaction->transaction_type
                : TransactionType::from((string) $transaction->transaction_type);

            if ($type === TransactionType::DOWN_PAYMENT) {
                $this->downPaymentService->applyExistingDownPaymentToSchedule($contract->fresh(), new CreateTransactionDTO(
                    contractId: $contract->id,
                    amount: $amount,
                    transactionDate: $date,
                    paymentMethod: $transaction->payment_method,
                    transactionType: TransactionType::DOWN_PAYMENT,
                    installmentNumbers: [],
                    notes: $transaction->notes,
                ));

                continue;
            }

            $option = $this->matchesCorrection($targets, $date->toDateString(), $amount)
                ? 'reducir_plazo'
                : $this->optionFromNotes((string) $transaction->notes);

            if ($option === 'reducir_plazo' && $this->matchesCorrection($targets, $date->toDateString(), $amount)) {
                $reapplied++;
            }

            try {
                $this->cascadeCollectionService->process(
                    $contract->id,
                    $amount,
                    $option,
                    $date,
                    [],
                    null,
                    $transaction->payment_method,
                    $transaction->notes,
                    false,
                );
            } catch (ValidationException $e) {
                $messages = $e->errors()['amount'] ?? [];
                $fulfilled = false;
                foreach ($messages as $message) {
                    if (str_contains((string) $message, 'obligación ya fue cumplida')) {
                        $fulfilled = true;
                    }
                }
                if (! $fulfilled) {
                    throw $e;
                }
                $warnings[] = sprintf(
                    '%s %s no se pudo aplicar a cuotas (obligación cumplida).',
                    $date->toDateString(),
                    $amount,
                );
            }
        }

        return ['reapplied' => $reapplied, 'warnings' => $warnings];
    }

    /**
     * @param  list<array{date: string, amount: string}>  $targets
     */
    private function matchesCorrection(array $targets, string $date, string $amount): bool
    {
        foreach ($targets as $target) {
            if ($target['date'] === $date && bccomp($target['amount'], $amount, 2) === 0) {
                return true;
            }
        }

        return false;
    }

    private function optionFromNotes(string $notes): ?string
    {
        if (! preg_match('/Concepto:\s*(.+?)(?:\s*\||$)/u', $notes, $match)) {
            return null;
        }

        $concept = trim($match[1]);
        $upper = mb_strtoupper($concept);

        if (str_contains($upper, 'ABONO EXTRA')
            || preg_match('/\+\s*ABONO/u', $upper)
            || preg_match('/CUOTA\s+\d+\s*\+/u', $upper)
        ) {
            return 'reducir_plazo';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Contract $contract): array
    {
        $rows = $contract->amortizationInstallments()->where('installment_number', '>', 0)->get();
        $byStatus = ['paid' => 0, 'partial' => 0, 'overdue' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $status = $row->status->value ?? (string) $row->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return [
            'contract_id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'term_months' => (int) $contract->term_months,
            'installments_n_gt_0' => $rows->count(),
            'paid' => $byStatus['paid'] ?? 0,
            'partial' => $byStatus['partial'] ?? 0,
            'overdue' => $byStatus['overdue'] ?? 0,
            'pending' => $byStatus['pending'] ?? 0,
            'extra_rows' => $rows->filter(fn ($row) => bccomp((string) $row->extra_payment, '0.00', 2) > 0)->count(),
            'extra_sum' => bcadd((string) $rows->sum('extra_payment'), '0', 2),
            'tx_count' => $contract->transactions()->count(),
            'tx_sum' => bcadd((string) $contract->transactions()->sum('amount'), '0', 2),
        ];
    }

    private function contractForLot(string $lotNumber): Contract
    {
        $contract = Contract::query()
            ->where('contract_number', 'SM-LOTE-'.$lotNumber)
            ->first();

        if (! $contract) {
            throw new \InvalidArgumentException("No existe SM-LOTE-{$lotNumber}");
        }

        return $contract;
    }
}
