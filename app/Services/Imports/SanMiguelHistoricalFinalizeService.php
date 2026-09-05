<?php

namespace App\Services\Imports;

use App\Imports\SanMiguel\SanMiguelHistoricalAlignments;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Amortization\AdjustInstallmentDueDatesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SanMiguelHistoricalFinalizeService
{
    public function __construct(
        private readonly ExcelScheduleImportService $excelScheduleImportService,
        private readonly AdjustInstallmentDueDatesService $dueDatesService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $workbookPath, ?string $soloLote = null): array
    {
        $overlayLots = ExcelScheduleImportService::LOTS;
        $orphanLots = array_keys(SanMiguelHistoricalAlignments::ORPHAN_EXTRAS_ON_FIRST_INSTALLMENT);
        $dateLots = $this->dateAlignmentLots();

        if ($soloLote !== null && $soloLote !== '') {
            $overlayLots = array_values(array_filter($overlayLots, fn ($lot) => $lot === $soloLote));
            $orphanLots = array_values(array_filter($orphanLots, fn ($lot) => $lot === $soloLote));
            $dateLots = array_values(array_filter($dateLots, fn ($lot) => $lot === $soloLote));
        }

        $overlay = [];
        foreach ($overlayLots as $lot) {
            $overlay[$lot] = count($this->excelScheduleImportService->apply($lot, $workbookPath));
        }

        $orphans = [];
        foreach ($orphanLots as $lot) {
            $orphans[$lot] = $this->applyOrphanExtra($lot);
        }

        $dates = [];
        foreach ($dateLots as $lot) {
            $dates[$lot] = $this->alignDueDates($lot);
        }

        return [
            'overlay_lots' => $overlay,
            'orphan_extras' => $orphans,
            'due_dates' => $dates,
        ];
    }

    /**
     * @return list<string>
     */
    private function dateAlignmentLots(): array
    {
        return array_values(array_unique(array_merge(
            SanMiguelHistoricalAlignments::SAME_DAY_CASCADE_FROM_FIRST,
            array_keys(SanMiguelHistoricalAlignments::SAME_DAY_CASCADE_SET_FIRST),
            SanMiguelHistoricalAlignments::MONTH_END_CASCADE_FROM_FIRST,
            [SanMiguelHistoricalAlignments::LOT_21],
        )));
    }

    private function applyOrphanExtra(string $lotNumber): string
    {
        $amount = SanMiguelHistoricalAlignments::ORPHAN_EXTRAS_ON_FIRST_INSTALLMENT[$lotNumber];
        $contract = $this->contract($lotNumber);
        $first = $contract->amortizationInstallments()
            ->where('installment_number', 1)
            ->first();
        if (! $first) {
            throw new \RuntimeException("SM-LOTE-{$lotNumber} no tiene cuota #1 para el abono huérfano.");
        }

        $receipt = $first->receipt_number;

        DB::transaction(function () use ($contract, $first, $amount) {
            $extra = bcadd((string) $first->extra_payment, $amount, 2);
            $remaining = $this->maxMoney('0.00', bcsub((string) $first->remaining_balance, $amount, 2));
            $principalPaid = bcadd((string) ($first->principal_paid ?? '0.00'), $amount, 2);
            $principalValue = bcadd((string) ($first->principal_value ?? '0.00'), $amount, 2);

            $first->update([
                'extra_payment' => $extra,
                'remaining_balance' => $remaining,
                'projected_balance' => $remaining,
                'principal_paid' => $principalPaid,
                'principal_value' => $principalValue,
            ]);

            $later = $contract->amortizationInstallments()
                ->where('installment_number', '>', 1)
                ->orderBy('installment_number')
                ->get();
            foreach ($later as $row) {
                $saldo = $this->maxMoney('0.00', bcsub((string) $row->remaining_balance, $amount, 2));
                $row->update([
                    'remaining_balance' => $saldo,
                    'projected_balance' => $saldo,
                ]);
            }
        });

        $fresh = $first->fresh();
        if ((string) $fresh->receipt_number !== (string) $receipt) {
            throw new \RuntimeException("SM-LOTE-{$lotNumber}: se alteró receipt_number de la cuota #1.");
        }

        return $amount;
    }

    private function alignDueDates(string $lotNumber): string
    {
        $contract = $this->contract($lotNumber);

        if ($lotNumber === SanMiguelHistoricalAlignments::LOT_21) {
            $second = $this->installment($contract, 2);
            $this->ensurePreviousDueIsBefore($contract, $second, SanMiguelHistoricalAlignments::LOT_21_SECOND_INSTALLMENT_DUE);
            $this->dueDatesService->apply(
                $contract,
                $second,
                SanMiguelHistoricalAlignments::LOT_21_SECOND_INSTALLMENT_DUE,
                AdjustInstallmentDueDatesService::MODE_CASCADE,
                AdjustInstallmentDueDatesService::CADENCE_SAME_DAY,
            );

            return 'cascade_from_2:'.SanMiguelHistoricalAlignments::LOT_21_SECOND_INSTALLMENT_DUE;
        }

        if (isset(SanMiguelHistoricalAlignments::SAME_DAY_CASCADE_SET_FIRST[$lotNumber])) {
            $due = SanMiguelHistoricalAlignments::SAME_DAY_CASCADE_SET_FIRST[$lotNumber];
            $first = $this->installment($contract, 1);
            $this->ensurePreviousDueIsBefore($contract, $first, $due);
            $this->dueDatesService->apply(
                $contract,
                $first,
                $due,
                AdjustInstallmentDueDatesService::MODE_CASCADE,
                AdjustInstallmentDueDatesService::CADENCE_SAME_DAY,
            );

            return 'set_first:'.$due;
        }

        $cadence = in_array($lotNumber, SanMiguelHistoricalAlignments::MONTH_END_CASCADE_FROM_FIRST, true)
            ? AdjustInstallmentDueDatesService::CADENCE_MONTH_END
            : AdjustInstallmentDueDatesService::CADENCE_SAME_DAY;

        $first = $this->installment($contract, 1);
        $due = Carbon::parse((string) $first->due_date)->toDateString();
        $this->ensurePreviousDueIsBefore($contract, $first, $due);
        $this->dueDatesService->apply(
            $contract,
            $first,
            $due,
            AdjustInstallmentDueDatesService::MODE_CASCADE,
            $cadence,
        );

        return $cadence.':'.$due;
    }

    private function ensurePreviousDueIsBefore(Contract $contract, AmortizationInstallment $installment, string $newDue): void
    {
        $previous = $contract->amortizationInstallments()
            ->where('installment_number', '<', (int) $installment->installment_number)
            ->orderByDesc('installment_number')
            ->first();
        if (! $previous || ! $previous->due_date) {
            return;
        }

        $prev = Carbon::parse((string) $previous->due_date)->startOfDay();
        $target = Carbon::parse($newDue)->startOfDay();
        if ($target->gt($prev)) {
            return;
        }

        $previous->update([
            'due_date' => $target->copy()->subMonthNoOverflow()->toDateString(),
        ]);
    }

    private function installment(Contract $contract, int $number): AmortizationInstallment
    {
        $row = $contract->amortizationInstallments()
            ->where('installment_number', $number)
            ->first();
        if (! $row) {
            throw new \RuntimeException("SM-LOTE-{$contract->contract_number} no tiene cuota #{$number}.");
        }

        return $row;
    }

    private function contract(string $lotNumber): Contract
    {
        $contract = Contract::query()->where('contract_number', 'SM-LOTE-'.$lotNumber)->first();
        if (! $contract) {
            throw new \RuntimeException("No existe SM-LOTE-{$lotNumber}");
        }

        return $contract;
    }

    private function maxMoney(string $left, string $right): string
    {
        return bccomp($left, $right, 2) >= 0 ? $left : $right;
    }
}
