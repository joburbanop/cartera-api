<?php

namespace App\Services\Imports;

use App\Imports\SanMiguel\SanMiguelHistoricalAlignments;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Amortization\AdjustInstallmentDueDatesService;
use Carbon\Carbon;

class SanMiguelHistoricalFinalizeService
{
    public function __construct(
        private readonly AdjustInstallmentDueDatesService $dueDatesService,
    ) {}

    /**
     * Alinea las fechas de vencimiento con la columna Nper del libro de
     * amortización. Es lo único que la hoja de vida no puede aportar: ahí solo
     * se anotan fechas de pago, nunca de vencimiento.
     *
     * @return array<string, mixed>
     */
    public function run(string $workbookPath, ?string $soloLote = null): array
    {
        $dateLots = $this->dateAlignmentLots();

        if ($soloLote !== null && $soloLote !== '') {
            $dateLots = array_values(array_filter($dateLots, fn ($lot) => $lot === $soloLote));
        }

        $dates = [];
        foreach ($dateLots as $lot) {
            $dates[$lot] = $this->alignDueDates($lot);
        }

        return [
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
}
