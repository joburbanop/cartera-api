<?php

namespace App\Services\Financial\Transaction\RegularPayment;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AmortizationStatus;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Financial\Amortization\AmortizationService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use App\Support\SafeUploadedFileName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegularPaymentService
{
    public function __construct(
        private readonly InstallmentPaymentAllocator $allocator,
    ) {}

    public function calculatePaymentImpact(
        AmortizationInstallment $plan,
        string $paymentAmount,
        ?Contract $contract = null
    ): array {
        $impact = $this->allocator->computeImpact($plan, $paymentAmount, $contract);

        return [
            'status' => $impact['status'],
            'quota_debt' => $impact['quota_debt'],
            'interest_paid' => $impact['interest_paid'],
            'principal_paid' => $impact['principal_paid'],
            'excedente' => $impact['excedente'],
        ];
    }

    public function registerRegularPayment(CreateTransactionDTO $dto): Transaction
{
    if ($dto->transactionType !== TransactionType::REGULAR_PAYMENT) {
        throw ValidationException::withMessages([
            'transaction_type' => 'Esta ruta solo permite registrar pagos regulares.',
        ]);
    }

    return DB::transaction(function () use ($dto) {

        $contract = Contract::findOrFail($dto->contractId);
        $planCollection = $contract->amortizationInstallments()->orderBy('installment_number', 'asc')->get();

        if ($planCollection->isEmpty()) {
            $planCollection = app(AmortizationService::class)->generateInitialProjection($contract);
        }

        $selectedInstallmentId = $dto->installmentNumbers[0] ?? null;

        if ($selectedInstallmentId === null) {
            throw ValidationException::withMessages([
                'installment_number' => 'Debe seleccionar una cuota.',
            ]);
        }

        $plan = $planCollection->first(fn ($installment) => (int) $installment->id === (int) $selectedInstallmentId)
            ?? $planCollection->first(fn ($installment) => (int) $installment->installment_number === (int) $selectedInstallmentId);

        if (! $plan) {
            throw ValidationException::withMessages([
                'installment_number' => 'La cuota seleccionada no existe.',
            ]);
        }

        $this->assertPaymentCanBeApplied($contract, $plan, $dto);

        $paymentForTarget = (string) $dto->amount;

        if ($this->hasExtraordinaryOption($dto)) {
            $paymentForTarget = $this->allocator->settlePriorUnpaidOrFail(
                $contract,
                $plan,
                $paymentForTarget,
                $dto->transactionDate,
            );
        } else {
            $toProcess = $this->allocator->resolveInstallmentsToProcess(
                $contract,
                [(int) $plan->id],
            );
            $prior = $toProcess->filter(
                fn (AmortizationInstallment $row) => (int) $row->id !== (int) $plan->id,
            );

            if ($prior->isNotEmpty()) {
                $settled = $this->allocator->cascadeToInstallments(
                    $prior,
                    $paymentForTarget,
                    $dto->transactionDate,
                    $contract,
                );
                $paymentForTarget = $settled['remaining'];
            }
        }

        $transaction = Transaction::create([
            'contract_id' => $contract->id,
            'transaction_type' => $dto->transactionType,
            'amount' => $dto->amount,
            'transaction_date' => $dto->transactionDate,
            'payment_method' => $dto->paymentMethod,
        ]);

        if ($dto->receipt) {
            $path = $dto->receipt->store('receipts', 'local');

            Receipt::create([
                'transaction_id' => $transaction->id,
                'file_path' => $path,
                    'file_name' => SafeUploadedFileName::forReceipt($dto->receipt),
                'file_type' => $dto->receipt->getClientMimeType(),
            ]);
        }

        if (bccomp($paymentForTarget, '0.00', 2) <= 0) {
            return $transaction;
        }

        $impact = $this->calculatePaymentImpact(
            $plan,
            $paymentForTarget,
            $contract
        );

        $projectedBalance = (string) ($plan->projected_balance ?? $plan->remaining_balance ?? '0.00');
        $surplus = (string) ($impact['excedente'] ?? '0.00');

        if (bccomp($surplus, '0.00', 2) > 0 && ! $this->hasExtraordinaryOption($dto)) {
            $plan->update([
                'status' => AmortizationStatus::PAID->value,
                'quota_debt' => '0.00',
                'payment_date' => $dto->transactionDate,
                'interest_paid' => (string) ($impact['interest_paid'] ?? '0.00'),
                'principal_paid' => (string) ($impact['principal_paid'] ?? '0.00'),
                'remaining_balance' => $projectedBalance,
                'projected_balance' => $projectedBalance,
            ]);

            $cascade = $this->allocator->cascadeToPending(
                $contract,
                $surplus,
                $dto->transactionDate,
                [(int) $plan->id],
            );

            if ($this->allocator->leftoverExceedsTolerance($cascade['remaining'])) {
                throw ValidationException::withMessages([
                    'amount' => 'La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.',
                ]);
            }

            return $transaction;
        }

        if (bccomp($surplus, '0.00', 2) > 0) {
            $previousInstallment = $contract->amortizationInstallments()
                ->where('installment_number', (int) ($plan->installment_number ?? 0) - 1)
                ->first();

            $startingBalance = $previousInstallment
                ? (float) ($previousInstallment->remaining_balance ?? $previousInstallment->projected_balance ?? 0)
                : (float) ($plan->projected_balance ?? $plan->remaining_balance ?? 0);

            if ($startingBalance <= 0) {
                $startingBalance = round((float) ($contract->sale_price ?? 0) - (float) ($contract->down_payment_pactada ?? 0), 2);
            }

            $interestValue = (float) ($plan->interest_value ?? 0);
            if ($interestValue <= 0) {
                $interestValue = round($startingBalance * ((float) ($contract->interest_rate ?? 0) / 100), 2);
            }

            $installmentValue = (string) ($plan->installment_value ?? '0.00');
            $regularPrincipal = round((float) $installmentValue - $interestValue, 2);
            $availableForExtraPayment = round(max(0.0, $startingBalance - $regularPrincipal), 2);
            $effectiveSurplus = round(min((float) $surplus, $availableForExtraPayment), 2);
            $totalPrincipalPaid = round($regularPrincipal + $effectiveSurplus, 2);
            $adjustedBalance = round(max(0.0, $startingBalance - $totalPrincipalPaid), 2);

            $plan->extra_payment = $effectiveSurplus;
            $plan->interest_value = $interestValue;
            $plan->principal_value = (string) $totalPrincipalPaid;
            $plan->status = AmortizationStatus::PAID->value;
            $plan->payment_date = $dto->transactionDate;
            $plan->remaining_balance = $adjustedBalance;
            $plan->projected_balance = $adjustedBalance;
            $plan->save();

            $plan->refresh();

            $recalculationType = strtolower((string) ($dto->recalculationType ?? $dto->paymentOption ?? 'reducir_plazo'));

            if ($recalculationType === 'reducir_plazo' || $recalculationType === 'reduce_term') {
                app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService::class)
                    ->recalculateFuture($contract, $plan->fresh());
            } elseif ($recalculationType === 'reducir_cuota' || $recalculationType === 'reduce_quota') {
                app(\App\Services\Financial\Transaction\ExtraordinaryPayment\Options\PaymentReductionService::class)
                    ->recalculateFuture($contract, $plan->fresh());
            }

            $option = strtolower((string) ($dto->paymentOption ?? ''));
            if ($option !== '') {
                $extraService = app(ExtraordinaryPaymentService::class);
                $extraService->handle($contract, $plan->fresh(), (string) $effectiveSurplus, $option);
            }

            return $transaction;
        }

        // computeImpact ya trae imputación acumulada, residual condonado y estado.
        $statusValue = $impact['status'] instanceof AmortizationStatus
            ? $impact['status']->value
            : (string) $impact['status'];

        $plan->update([
            'status' => $statusValue,
            'quota_debt' => $impact['quota_debt'],
            'payment_date' => $dto->transactionDate,
            'interest_paid' => (string) ($impact['interest_paid'] ?? '0.00'),
            'principal_paid' => (string) ($impact['principal_paid'] ?? '0.00'),
            'remaining_balance' => $projectedBalance,
            'projected_balance' => $projectedBalance,
        ]);

        return $transaction;
    });
}

    private function hasExtraordinaryOption(CreateTransactionDTO $dto): bool
    {
        $candidates = [
            strtolower(trim((string) ($dto->paymentOption ?? ''))),
            strtolower(trim((string) ($dto->recalculationType ?? ''))),
        ];

        foreach ($candidates as $candidate) {
            $normalized = match ($candidate) {
                'reduce_time', 'reducir_plazo', 'reduce_term' => 'reducir_plazo',
                'reduce_quota', 'reducir_cuota' => 'reducir_cuota',
                'transfer', 'adelantar_cuotas' => 'adelantar_cuotas',
                default => $candidate,
            };

            if (in_array($normalized, ['reducir_plazo', 'reducir_cuota', 'adelantar_cuotas'], true)) {
                return true;
            }
        }

        return false;
    }

    private function assertPaymentCanBeApplied(
        Contract $contract,
        AmortizationInstallment $plan,
        CreateTransactionDTO $dto,
    ): void {
        if ($this->hasExtraordinaryOption($dto)) {
            return;
        }

        $quotaDebt = number_format((float) ($plan->quota_debt ?? '0.00'), 2, '.', '');
        $stillOpen = $plan->status !== AmortizationStatus::PAID && bccomp($quotaDebt, '0.00', 2) > 0;

        if ($stillOpen) {
            return;
        }

        if ($this->allocator->pendingInstallments($contract, [(int) $plan->id])->isNotEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => 'La obligación ya fue cumplida, no hay saldo pendiente para aplicar este pago.',
        ]);
    }
}