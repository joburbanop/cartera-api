<?php

namespace App\Services\Collection;

use App\DTOs\CreateTransactionDTO;
use App\Enums\AllocationTarget;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Financial\Transaction\DownPayment\DownPaymentService;
use App\Support\DownPaymentLedger;
use App\Support\SafeUploadedFileName;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un solo pago del cliente repartido entre la cuota inicial y las cuotas
 * regulares.
 *
 * Pasa cuando la inicial está en mora: el cliente consigna una cifra que cubre
 * el faltante de la inicial más la cuota del mes. El banco ve ese único
 * movimiento, así que se guarda UNA transacción por el total y el reparto se
 * anota en `transaction_allocations`. Así la conciliación bancaria cuadra sin
 * que nadie tenga que recordar sumar dos filas.
 *
 * La imputación se delega a los servicios que ya existen: ni la inicial ni las
 * cuotas cambian de reglas por venir en un pago repartido.
 */
class SplitPaymentService
{
    public function __construct(
        private readonly DownPaymentService $downPaymentService,
        private readonly CascadeCollectionService $cascadeCollectionService,
        private readonly TransactionAllocationRecorder $allocationRecorder,
    ) {}

    /**
     * @param  list<int>  $selectedInstallmentIds
     * @return array<string, mixed>
     */
    public function process(
        int $contractId,
        string $toDownPayment,
        string $toInstallments,
        ?Carbon $transactionDate = null,
        array $selectedInstallmentIds = [],
        ?UploadedFile $receipt = null,
        ?PaymentMethod $paymentMethod = null,
        ?string $notes = null,
        ?string $paymentOption = null,
    ): array {
        return DB::transaction(function () use (
            $contractId,
            $toDownPayment,
            $toInstallments,
            $transactionDate,
            $selectedInstallmentIds,
            $receipt,
            $paymentMethod,
            $notes,
            $paymentOption,
        ) {
            $contract = Contract::query()->with('lot')->findOrFail($contractId);
            $initialPart = $this->money($toDownPayment);
            $regularPart = $this->money($toInstallments);
            $total = bcadd($initialPart, $regularPart, 2);
            $effectiveDate = ($transactionDate ?? Carbon::now())->copy()->startOfDay();
            $method = $paymentMethod ?? PaymentMethod::CASH;

            $this->assertPartsAreValid($contract, $initialPart, $regularPart);

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'transaction_type' => TransactionType::SPLIT_PAYMENT,
                'amount' => $total,
                'transaction_date' => $effectiveDate->toDateString(),
                'payment_method' => $method,
                'notes' => $notes,
            ]);

            if ($receipt) {
                $path = $receipt->store('receipts', 'local');

                Receipt::create([
                    'transaction_id' => $transaction->id,
                    'file_path' => $path,
                    'file_name' => SafeUploadedFileName::forReceipt($receipt),
                    'file_type' => $receipt->getClientMimeType(),
                ]);
            }

            // La inicial primero: si queda saldada, el contrato se activa y las
            // cuotas regulares dejan de estar suprimidas por preventa.
            $initialAllocation = $this->applyToDownPayment(
                $contract,
                $transaction,
                $initialPart,
                $effectiveDate,
                $method,
                $notes,
            );

            $cascade = $this->applyToInstallments(
                $contract,
                $transaction,
                $regularPart,
                $effectiveDate,
                $selectedInstallmentIds,
                $method,
                $notes,
                $paymentOption,
            );

            return [
                'transaction_id' => $transaction->id,
                'contract_id' => $contract->id,
                'amount' => $total,
                'amount_applied' => $total,
                'remaining_amount' => '0.00',
                'down_payment_applied' => $initialAllocation,
                'cascade_applied' => $cascade['amount_applied'] ?? '0.00',
                'installments' => $cascade['installments'] ?? [],
            ];
        });
    }

    private function assertPartsAreValid(Contract $contract, string $initialPart, string $regularPart): void
    {
        if (bccomp($initialPart, '0.00', 2) <= 0 || bccomp($regularPart, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'split' => 'Un pago dividido necesita monto tanto para la cuota inicial como para la cuota regular.',
            ]);
        }

        $pendingInitial = DownPaymentLedger::pending($contract);

        if (bccomp($pendingInitial, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'to_down_payment' => 'La cuota inicial ya está saldada: registra el pago como cuota regular.',
            ]);
        }

        if (bccomp($initialPart, $pendingInitial, 2) === 1) {
            throw ValidationException::withMessages([
                'to_down_payment' => 'La parte destinada a la cuota inicial supera su saldo pendiente de $'
                    .number_format((float) $pendingInitial, 2, ',', '.').'.',
            ]);
        }
    }

    private function applyToDownPayment(
        Contract $contract,
        Transaction $transaction,
        string $amount,
        Carbon $date,
        PaymentMethod $method,
        ?string $notes,
    ): string {
        $initial = $contract->amortizationInstallments()
            ->where('installment_number', 0)
            ->first();
        $before = $this->allocationRecorder->snapshot($initial);

        // Se anota antes de imputar: los servicios de cuota inicial consultan
        // el reparto para saber cuánto se ha recaudado y decidir si activan el
        // contrato.
        $allocation = TransactionAllocation::create([
            'transaction_id' => $transaction->id,
            'target' => AllocationTarget::DOWN_PAYMENT,
            'amortization_installment_id' => $initial?->id,
            'amount' => $amount,
            'principal' => $amount,
            'interest' => '0.00',
        ]);

        $this->downPaymentService->applyExistingDownPaymentToSchedule(
            $contract,
            new CreateTransactionDTO(
                contractId: $contract->id,
                amount: $amount,
                transactionDate: $date,
                paymentMethod: $method,
                transactionType: TransactionType::DOWN_PAYMENT,
                installmentNumbers: [],
                notes: $notes,
            )
        );

        // La cuota inicial es capital puro, salvo que el plan le haya asignado
        // interés; en ese caso el delta real manda.
        $delta = $this->allocationRecorder->delta($before, $initial?->fresh());
        if (bccomp($delta['interest'], '0.00', 2) > 0) {
            $allocation->update([
                'principal' => $delta['principal'],
                'interest' => $delta['interest'],
            ]);
        }

        return $amount;
    }

    /**
     * @param  list<int>  $selectedInstallmentIds
     * @return array<string, mixed>
     */
    private function applyToInstallments(
        Contract $contract,
        Transaction $transaction,
        string $amount,
        Carbon $date,
        array $selectedInstallmentIds,
        PaymentMethod $method,
        ?string $notes,
        ?string $paymentOption,
    ): array {
        // `persistTransaction: false` imputa sin crear una segunda transacción:
        // el movimiento bancario ya quedó registrado arriba. Las allocations
        // de cuota/capital y el vínculo a promesa los escribe la cascada.
        return $this->cascadeCollectionService->process(
            contractId: $contract->id,
            amount: $amount,
            paymentOption: $paymentOption,
            transactionDate: $date,
            selectedInstallmentIds: $selectedInstallmentIds,
            receipt: null,
            paymentMethod: $method,
            notes: $notes,
            persistTransaction: false,
            allocationTransactionId: $transaction->id,
        );
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
