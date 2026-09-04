<?php

namespace App\Services\Collection;

use App\DTOs\CreateTransactionDTO;
use App\Enums\LotStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PreventaThenCascadeCollectionService
{
    public function __construct(
        private readonly DownPaymentService $downPaymentService,
        private readonly CascadeCollectionService $cascadeCollectionService,
    ) {}

    /**
     * @param  list<int>  $selectedInstallmentIds
     * @return array<string, mixed>
     */
    public function process(
        int $contractId,
        string $amount,
        ?string $paymentOption = null,
        ?Carbon $transactionDate = null,
        array $selectedInstallmentIds = [],
        ?UploadedFile $receipt = null,
        ?PaymentMethod $paymentMethod = null,
        ?string $notes = null,
    ): array {
        return DB::transaction(function () use (
            $contractId,
            $amount,
            $paymentOption,
            $transactionDate,
            $selectedInstallmentIds,
            $receipt,
            $paymentMethod,
            $notes,
        ) {
            $contract = Contract::query()->with('lot')->findOrFail($contractId);
            $normalizedAmount = $this->money($amount);
            $effectiveDate = ($transactionDate ?? Carbon::now())->copy()->startOfDay();
            $method = $paymentMethod ?? PaymentMethod::CASH;

            if (! $this->shouldApplyInitialFirst($contract)) {
                return $this->cascadeCollectionService->process(
                    $contractId,
                    $normalizedAmount,
                    $paymentOption,
                    $effectiveDate,
                    $selectedInstallmentIds,
                    $receipt,
                    $method,
                    $notes,
                );
            }

            $pendingInitial = $this->pendingInitial($contract);
            $toInicial = bccomp($normalizedAmount, $pendingInitial, 2) === 1
                ? $pendingInitial
                : $normalizedAmount;
            $remainder = bcsub($normalizedAmount, $toInicial, 2);

            $downPayment = $this->downPaymentService->registerDownPayment(new CreateTransactionDTO(
                contractId: $contract->id,
                amount: $toInicial,
                transactionDate: $effectiveDate,
                paymentMethod: $method,
                transactionType: TransactionType::DOWN_PAYMENT,
                installmentNumbers: [],
                notes: $notes,
                receipt: $receipt,
            ));

            $cascade = null;
            if (bccomp($remainder, '0.00', 2) === 1) {
                $cascade = $this->cascadeCollectionService->process(
                    $contract->id,
                    $remainder,
                    $paymentOption,
                    $effectiveDate,
                    $selectedInstallmentIds,
                    null,
                    $method,
                    $notes,
                );
            }

            return [
                'transaction_id' => $cascade['transaction_id'] ?? $downPayment->id,
                'down_payment_transaction_id' => $downPayment->id,
                'contract_id' => $contract->id,
                'amount' => $normalizedAmount,
                'amount_applied' => $normalizedAmount,
                'remaining_amount' => '0.00',
                'installments' => $cascade['installments'] ?? [],
                'down_payment_applied' => $toInicial,
                'cascade_applied' => $cascade['amount_applied'] ?? '0.00',
            ];
        });
    }

    private function shouldApplyInitialFirst(Contract $contract): bool
    {
        $lotStatus = $contract->lot?->status;
        $isPreventa = $lotStatus === LotStatus::PREVENTA
            || (is_string($lotStatus) && $lotStatus === LotStatus::PREVENTA->value);

        if (! $isPreventa) {
            return false;
        }

        return bccomp($this->pendingInitial($contract), '500.00', 2) >= 0;
    }

    private function pendingInitial(Contract $contract): string
    {
        $totalPaid = $contract->transactions()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT)
            ->sum('amount');

        $pending = bcsub((string) $contract->down_payment_pactada, (string) $totalPaid, 2);

        return bccomp($pending, '0.00', 2) > 0 ? $this->money($pending) : '0.00';
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
