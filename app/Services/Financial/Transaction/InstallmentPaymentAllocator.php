<?php

namespace App\Services\Financial\Transaction;

use App\Enums\AmortizationStatus;
use App\Enums\ContractStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Residual\ResidualBalanceService;
use App\Support\DueDateRules;
use App\Support\FinancialRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentAllocator
{
    public function __construct(
        private readonly ResidualBalanceService $residualBalanceService,
    ) {}

    /**
     * Imputación interés→capital, polvo de centavos y condonación de residual.
     * No escribe en base. El excedente (pago − deuda) se normaliza con ABSORBED_SURPLUS.
     *
     * @return array{
     *     applied: string,
     *     quota_debt: string,
     *     status: AmortizationStatus,
     *     interest_paid: string,
     *     principal_paid: string,
     *     excedente: string
     * }
     */
    public function computeImpact(
        AmortizationInstallment $installment,
        string $paymentAmount,
        ?Contract $contract = null,
    ): array {
        $paymentAmount = $this->normalizeMoney($paymentAmount);
        $installmentValue = $this->normalizeMoney((string) ($installment->installment_value ?? '0.00'));
        $interestValue = $this->normalizeMoney((string) ($installment->interest_value ?? '0.00'));
        $principalValue = $this->normalizeMoney((string) (
            $installment->principal_value
            ?? bcsub($installmentValue, $interestValue, 2)
        ));
        $interestAlreadyPaid = $this->normalizeMoney((string) ($installment->interest_paid ?? '0.00'));
        $principalAlreadyPaid = $this->normalizeMoney((string) ($installment->principal_paid ?? '0.00'));
        $pendingDebt = $this->pendingDebtOf($installment);

        if (bccomp($paymentAmount, '0.00', 2) <= 0) {
            return [
                'applied' => '0.00',
                'quota_debt' => $pendingDebt,
                'status' => $this->statusEnum($installment),
                'interest_paid' => $interestAlreadyPaid,
                'principal_paid' => $principalAlreadyPaid,
                'excedente' => '0.00',
            ];
        }

        $amountToDebt = bccomp($paymentAmount, $pendingDebt, 2) <= 0
            ? $paymentAmount
            : $pendingDebt;

        $remainingInterest = $this->maxZero(bcsub($interestValue, $interestAlreadyPaid, 2));
        $interestApplied = bccomp($amountToDebt, $remainingInterest, 2) <= 0
            ? $amountToDebt
            : $remainingInterest;
        $leftForPrincipal = $this->maxZero(bcsub($amountToDebt, $interestApplied, 2));
        $remainingPrincipal = $this->maxZero(bcsub($principalValue, $principalAlreadyPaid, 2));
        $principalApplied = bccomp($leftForPrincipal, $remainingPrincipal, 2) <= 0
            ? $leftForPrincipal
            : $remainingPrincipal;

        $newInterestPaid = bcadd($interestAlreadyPaid, $interestApplied, 2);
        $newPrincipalPaid = bcadd($principalAlreadyPaid, $principalApplied, 2);
        $newBalanceDue = $this->maxZero(bcsub($pendingDebt, $amountToDebt, 2));

        if (FinancialRules::isImputationDust($newBalanceDue)) {
            $newBalanceDue = '0.00';
        }

        if (FinancialRules::residualIsWithinCompletionTolerance($newBalanceDue)) {
            $newBalanceDue = '0.00';
        }

        $isPaid = bccomp($newBalanceDue, '0.00', 2) <= 0;

        if ($isPaid) {
            $newInterestPaid = $interestValue;
            $newPrincipalPaid = $principalValue;
        }

        $surplus = '0.00';
        if (bccomp($paymentAmount, $pendingDebt, 2) > 0) {
            $rawSurplus = $this->normalizeMoney(bcsub($paymentAmount, $pendingDebt, 2));
            $surplus = FinancialRules::leftoverExceedsAbsorbedSurplus($rawSurplus) ? $rawSurplus : '0.00';
        }

        $status = $isPaid
            ? AmortizationStatus::PAID
            : $this->resolvePartialStatus($installment, $contract);

        return [
            'applied' => $amountToDebt,
            'quota_debt' => $newBalanceDue,
            'status' => $status,
            'interest_paid' => $newInterestPaid,
            'principal_paid' => $newPrincipalPaid,
            'excedente' => $surplus,
        ];
    }

    public function applyToInstallment(
        AmortizationInstallment $installment,
        string $paymentAmount,
        Carbon $paymentDate,
        ?Contract $contract = null,
    ): array {
        $pendingDebt = $this->pendingDebtOf($installment);
        $impact = $this->computeImpact($installment, $paymentAmount, $contract ?? $installment->contract);
        $statusValue = $impact['status'] instanceof AmortizationStatus
            ? $impact['status']->value
            : (string) $impact['status'];

        if (bccomp($impact['applied'], '0.00', 2) > 0 || $statusValue === AmortizationStatus::PAID->value) {
            $installment->update([
                'quota_debt' => $impact['quota_debt'],
                'status' => $statusValue,
                'payment_date' => $paymentDate->toDateString(),
                'interest_paid' => $impact['interest_paid'],
                'principal_paid' => $impact['principal_paid'],
            ]);

            $forgiven = $this->maxZero(bcsub($pendingDebt, $impact['applied'], 2));
            $quotaClosed = bccomp($impact['quota_debt'], '0.00', 2) <= 0
                && $statusValue === AmortizationStatus::PAID->value;

            $this->residualBalanceService->recordIfMinor(
                (int) $installment->contract_id,
                (int) $installment->id,
                $forgiven,
                $quotaClosed,
            );
        }

        return [
            'applied' => $impact['applied'],
            'quota_debt' => $impact['quota_debt'],
            'status' => $statusValue,
            'interest_paid' => $impact['interest_paid'],
            'principal_paid' => $impact['principal_paid'],
            'excedente' => $impact['excedente'],
        ];
    }

    public function cascadeToPending(
        Contract $contract,
        string $amount,
        Carbon $paymentDate,
        array $excludeIds = [],
    ): array {
        return $this->cascadeToInstallments(
            $this->pendingInstallments($contract, $excludeIds),
            $amount,
            $paymentDate,
            $contract,
        );
    }

    public function cascadeToInstallments(
        iterable $installments,
        string $amount,
        Carbon $paymentDate,
        ?Contract $contract = null,
    ): array {
        $available = $this->normalizeMoney($amount);
        $appliedInstallments = [];

        foreach ($installments as $installment) {
            if (bccomp($available, '0.00', 2) <= 0) {
                break;
            }

            if ($installment->status === AmortizationStatus::PAID) {
                continue;
            }

            $result = $this->applyToInstallment(
                $installment,
                $available,
                $paymentDate,
                $contract,
            );

            if (bccomp($result['applied'], '0.00', 2) <= 0) {
                continue;
            }

            $available = $this->normalizeMoney(bcsub($available, $result['applied'], 2));
            $appliedInstallments[] = [
                'installment_id' => $installment->id,
                'installment_number' => $installment->installment_number,
                'amount_applied' => $result['applied'],
                'balance_due' => $result['quota_debt'],
                'status' => $result['status'],
            ];
        }

        return [
            'remaining' => $available,
            'installments' => $appliedInstallments,
        ];
    }

    public function priorUnpaidInstallments(Contract $contract, AmortizationInstallment $target): EloquentCollection
    {
        $targetNumber = (int) ($target->installment_number ?? 0);

        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('installment_number', '<', $targetNumber)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('status', AmortizationStatus::OVERDUE->value);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc')
            ->get();
    }

    public function settlePriorUnpaidOrFail(
        Contract $contract,
        AmortizationInstallment $target,
        string $amount,
        Carbon $paymentDate,
    ): string {
        $amount = $this->normalizeMoney($amount);
        $prior = $this->priorUnpaidInstallments($contract, $target);
        $priorDebt = $this->sumQuotaDebt($prior);

        if (bccomp($priorDebt, '0.00', 2) <= 0) {
            return $amount;
        }

        if (bccomp($amount, $priorDebt, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'Debe saldar primero las cuotas atrasadas antes de aplicar un abono extraordinario.',
            ]);
        }

        $result = $this->cascadeToInstallments($prior, $amount, $paymentDate, $contract);

        return $result['remaining'];
    }

    public function resolveInstallmentsToProcess(Contract $contract, array $explicitlySelectedIds): EloquentCollection
    {
        $selectedIds = array_values(array_unique(array_filter(
            array_map('intval', $explicitlySelectedIds),
            fn (int $id) => $id > 0,
        )));

        $overdue = $this->unpaidOverdueInstallments($contract);
        $overdueIds = $overdue->map(fn (AmortizationInstallment $row) => (int) $row->id)->all();

        $current = $this->unpaidCurrentInstallments($contract)
            ->filter(fn (AmortizationInstallment $row) => ! in_array((int) $row->id, $overdueIds, true))
            ->values();
        $currentIds = $current->map(fn (AmortizationInstallment $row) => (int) $row->id)->all();
        $alreadyQueued = array_values(array_unique([...$overdueIds, ...$currentIds]));

        $selected = new EloquentCollection;

        if ($selectedIds !== []) {
            $byId = $contract->amortizationInstallments()
                ->where('installment_number', '>', 0)
                ->whereIn('id', $selectedIds)
                ->get()
                ->keyBy(fn (AmortizationInstallment $row) => (int) $row->id);

            foreach ($selectedIds as $id) {
                if (in_array($id, $alreadyQueued, true)) {
                    continue;
                }

                $row = $byId->get($id);
                if ($row instanceof AmortizationInstallment) {
                    $selected->push($row);
                }
            }
        }

        return $overdue->values()->concat($current)->concat($selected)->values();
    }

    /**
     * Cuota vigente del mes: due_date en el mes calendario de hoy y aún no vencida.
     * Misma excepción de preventa que la mora regular.
     */
    public function unpaidCurrentInstallments(Contract $contract): EloquentCollection
    {
        if ($this->suppressesRegularOverdue($contract)) {
            return new EloquentCollection;
        }

        $today = DueDateRules::asOfDate();
        $endOfMonth = Carbon::parse($today)->endOfMonth()->toDateString();

        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $endOfMonth)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('status', AmortizationStatus::OVERDUE->value);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc')
            ->get();
    }

    public function unpaidOverdueInstallments(Contract $contract): EloquentCollection
    {
        if ($this->suppressesRegularOverdue($contract)) {
            return new EloquentCollection;
        }

        $today = DueDateRules::asOfDate();

        return $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->calendarOverdue($today)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('status', AmortizationStatus::OVERDUE->value);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc')
            ->get();
    }

    public function pendingInstallments(
        Contract $contract,
        array $excludeIds = [],
        array $onlyIds = [],
    ): EloquentCollection {
        $query = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->where('status', '!=', AmortizationStatus::PAID->value)
            ->where(function ($query) {
                $query->where('quota_debt', '>', 0)
                    ->orWhere('remaining_balance', '>', 0);
            })
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_number', 'asc');

        if ($onlyIds !== []) {
            $query->whereIn('id', array_values(array_filter(array_map('intval', $onlyIds), fn (int $id) => $id > 0)));
        }

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }

    public function leftoverExceedsTolerance(string $amount): bool
    {
        return FinancialRules::leftoverExceedsAbsorbedSurplus($this->normalizeMoney($amount));
    }

    public function resolvePartialStatus(AmortizationInstallment $plan, ?Contract $contract = null): AmortizationStatus
    {
        if ($contract && $contract->status === ContractStatus::PREVENTA_INACTIVA) {
            return AmortizationStatus::PARTIAL;
        }

        $dueDate = $plan->due_date ?? null;

        if (! $dueDate) {
            return AmortizationStatus::OVERDUE;
        }

        return DueDateRules::isOverdue($dueDate)
            ? AmortizationStatus::OVERDUE
            : AmortizationStatus::PARTIAL;
    }

    private function sumQuotaDebt(iterable $installments): string
    {
        $total = '0.00';

        foreach ($installments as $installment) {
            $debt = $this->normalizeMoney((string) ($installment->quota_debt ?? '0.00'));
            if (bccomp($debt, '0.00', 2) > 0) {
                $total = $this->normalizeMoney(bcadd($total, $debt, 2));
            }
        }

        return $total;
    }

    public function suppressesRegularOverdue(Contract $contract): bool
    {
        if ($contract->status !== ContractStatus::PREVENTA_INACTIVA) {
            return false;
        }

        $initial = null;
        if ($contract->relationLoaded('amortizationInstallments')) {
            $initial = $contract->amortizationInstallments->first(
                fn (AmortizationInstallment $row) => (int) $row->installment_number === 0,
            );
        } elseif ($contract->relationLoaded('installments')) {
            $initial = $contract->installments->first(
                fn (AmortizationInstallment $row) => (int) $row->installment_number === 0,
            );
        } else {
            $initial = $contract->amortizationInstallments()->where('installment_number', 0)->first();
        }

        if (! $initial instanceof AmortizationInstallment) {
            return true;
        }

        $debt = $this->normalizeMoney((string) ($initial->quota_debt ?? '0.00'));
        if (bccomp($debt, '0.00', 2) <= 0) {
            $value = $this->normalizeMoney((string) ($initial->installment_value ?? '0.00'));
            $paid = bcadd(
                $this->normalizeMoney((string) ($initial->interest_paid ?? '0.00')),
                $this->normalizeMoney((string) ($initial->principal_paid ?? '0.00')),
                2,
            );
            $debt = $this->maxZero(bcsub($value, $paid, 2));
        }

        return ! FinancialRules::residualIsWithinCompletionTolerance($debt);
    }

    private function pendingDebtOf(AmortizationInstallment $installment): string
    {
        $installmentValue = $this->normalizeMoney((string) ($installment->installment_value ?? '0.00'));
        $interestAlreadyPaid = $this->normalizeMoney((string) ($installment->interest_paid ?? '0.00'));
        $principalAlreadyPaid = $this->normalizeMoney((string) ($installment->principal_paid ?? '0.00'));
        $currentQuotaDebt = $this->normalizeMoney((string) ($installment->quota_debt ?? '0.00'));

        return bccomp($currentQuotaDebt, '0.00', 2) > 0
            ? $currentQuotaDebt
            : $this->maxZero(bcsub($installmentValue, bcadd($interestAlreadyPaid, $principalAlreadyPaid, 2), 2));
    }

    private function statusEnum(AmortizationInstallment $installment): AmortizationStatus
    {
        $status = $installment->status;

        if ($status instanceof AmortizationStatus) {
            return $status;
        }

        return AmortizationStatus::tryFrom((string) $status) ?? AmortizationStatus::PENDING;
    }

    private function maxZero(string $value): string
    {
        return bccomp($value, '0.00', 2) < 0 ? '0.00' : $this->normalizeMoney($value);
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
