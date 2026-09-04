<?php

namespace App\Services\Financial\Amortization;

use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustInstallmentDueDatesService
{
    public const MODE_SINGLE = 'single';

    public const MODE_CASCADE = 'cascade';

    public const CADENCE_SAME_DAY = 'same_day';

    public const CADENCE_MONTH_END = 'month_end';

    /**
     * @return array<string, mixed>
     */
    public function preview(
        Contract $contract,
        AmortizationInstallment $installment,
        string $dueDate,
        string $mode,
        string $cadence = self::CADENCE_SAME_DAY,
    ): array {
        return $this->buildPlan($contract, $installment, $dueDate, $mode, $cadence);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        Contract $contract,
        AmortizationInstallment $installment,
        string $dueDate,
        string $mode,
        string $cadence = self::CADENCE_SAME_DAY,
    ): array {
        return DB::transaction(function () use ($contract, $installment, $dueDate, $mode, $cadence) {
            $plan = $this->buildPlan($contract, $installment, $dueDate, $mode, $cadence);

            foreach ($plan['changes'] as $change) {
                AmortizationInstallment::query()
                    ->whereKey($change['id'])
                    ->update(['due_date' => $change['due_date_after']]);
            }

            if ($plan['updates_contract_anchor']) {
                $contract->fill([
                    'first_installment_date' => $plan['anchor_after'],
                    'regular_payment_start_date' => $plan['anchor_after'],
                ]);
                $contract->saveQuietly();
            }

            foreach ($plan['promise_changes'] as $change) {
                $contract->paymentPromises()
                    ->whereKey($change['id'])
                    ->update(['expected_date' => $change['expected_date_after']]);
            }

            $this->logActivity($contract, $installment, $plan);

            return $plan;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(
        Contract $contract,
        AmortizationInstallment $installment,
        string $dueDate,
        string $mode,
        string $cadence = self::CADENCE_SAME_DAY,
    ): array {
        $mode = $this->assertMode($mode);
        $cadence = $this->assertCadence($mode, $cadence);
        $this->assertRegularInstallment($installment);

        $newDue = Carbon::parse($dueDate)->startOfDay();
        $installments = $contract->amortizationInstallments()
            ->orderBy('installment_number')
            ->orderBy('id')
            ->get();

        $currentNumber = (int) $installment->installment_number;
        $current = $installments->first(
            fn (AmortizationInstallment $row) => (int) $row->id === (int) $installment->id
        );

        if (! $current) {
            abort(404);
        }

        $previous = $installments
            ->filter(fn (AmortizationInstallment $row) => (int) $row->installment_number < $currentNumber)
            ->last();
        $next = $installments
            ->first(fn (AmortizationInstallment $row) => (int) $row->installment_number > $currentNumber);

        $minExclusive = $previous?->due_date
            ? Carbon::parse((string) $previous->due_date)->startOfDay()
            : null;
        $maxExclusive = ($mode === self::MODE_SINGLE && $next?->due_date)
            ? Carbon::parse((string) $next->due_date)->startOfDay()
            : null;

        $this->assertWithinBounds($newDue, $minExclusive, $maxExclusive, $mode);

        $targets = $mode === self::MODE_CASCADE
            ? $installments->filter(
                fn (AmortizationInstallment $row) => (int) $row->installment_number >= $currentNumber
            )->values()
            : collect([$current]);

        $changes = $targets->values()->map(function (AmortizationInstallment $row, int $offset) use ($newDue, $cadence) {
            return [
                'id' => (int) $row->id,
                'installment_number' => (int) $row->installment_number,
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status,
                'due_date_before' => Carbon::parse((string) $row->due_date)->toDateString(),
                'due_date_after' => $this->shiftDueDate($newDue, $offset, $cadence),
                'payment_date' => $row->payment_date
                    ? Carbon::parse((string) $row->payment_date)->toDateString()
                    : null,
            ];
        })->all();

        $shiftPromises = (bool) $contract->is_custom_plan;
        $promiseChanges = $shiftPromises
            ? $this->promiseChanges($contract, $currentNumber, $newDue, $mode, $cadence)
            : [];

        $updatesAnchor = $mode === self::MODE_CASCADE && $currentNumber === 1;
        $anchorBefore = $contract->first_installment_date
            ? Carbon::parse((string) $contract->first_installment_date)->toDateString()
            : null;

        return [
            'mode' => $mode,
            'cadence' => $cadence,
            'installment_number' => $currentNumber,
            'is_custom_plan' => $shiftPromises,
            'shifts_promises' => $shiftPromises,
            'updates_contract_anchor' => $updatesAnchor,
            'anchor_before' => $anchorBefore,
            'anchor_after' => $updatesAnchor ? $newDue->toDateString() : $anchorBefore,
            'min_due_date' => $minExclusive?->toDateString(),
            'max_due_date' => $maxExclusive?->toDateString(),
            'affected_count' => count($changes),
            'promises_affected_count' => count($promiseChanges),
            'changes' => $changes,
            'promise_changes' => $promiseChanges,
            'preview' => $this->samplePreview($changes),
        ];
    }

    /**
     * @return list<array{id: int, payment_number: int, expected_date_before: string, expected_date_after: string}>
     */
    private function promiseChanges(
        Contract $contract,
        int $fromPaymentNumber,
        Carbon $newDue,
        string $mode,
        string $cadence,
    ): array {
        $promises = $contract->paymentPromises()
            ->orderBy('payment_number')
            ->orderBy('id')
            ->get();

        $targets = $mode === self::MODE_CASCADE
            ? $promises->filter(fn ($row) => (int) $row->payment_number >= $fromPaymentNumber)->values()
            : $promises->filter(fn ($row) => (int) $row->payment_number === $fromPaymentNumber)->values();

        return $targets->values()->map(function ($row, int $offset) use ($newDue, $cadence) {
            return [
                'id' => (int) $row->id,
                'payment_number' => (int) $row->payment_number,
                'expected_date_before' => Carbon::parse((string) $row->expected_date)->toDateString(),
                'expected_date_after' => $this->shiftDueDate($newDue, $offset, $cadence),
            ];
        })->all();
    }

    public function shiftDueDate(Carbon $origin, int $offset, string $cadence): string
    {
        if ($cadence === self::CADENCE_MONTH_END) {
            return $origin->copy()->startOfMonth()->addMonths($offset)->endOfMonth()->toDateString();
        }

        return $origin->copy()->addMonthsNoOverflow($offset)->toDateString();
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    private function samplePreview(array $changes): array
    {
        $count = count($changes);
        if ($count <= 10) {
            return $changes;
        }

        $head = array_slice($changes, 0, 5);
        $tail = array_slice($changes, -5);

        return array_values(array_merge($head, $tail));
    }

    private function assertMode(string $mode): string
    {
        if (! in_array($mode, [self::MODE_SINGLE, self::MODE_CASCADE], true)) {
            throw ValidationException::withMessages([
                'mode' => 'El modo debe ser single o cascade.',
            ]);
        }

        return $mode;
    }

    private function assertCadence(string $mode, string $cadence): string
    {
        $cadence = trim($cadence) !== '' ? $cadence : self::CADENCE_SAME_DAY;

        if ($mode === self::MODE_SINGLE) {
            return self::CADENCE_SAME_DAY;
        }

        if (! in_array($cadence, [self::CADENCE_SAME_DAY, self::CADENCE_MONTH_END], true)) {
            throw ValidationException::withMessages([
                'cadence' => 'La cadencia debe ser same_day o month_end.',
            ]);
        }

        return $cadence;
    }

    private function assertRegularInstallment(AmortizationInstallment $installment): void
    {
        if ((int) $installment->installment_number <= 0) {
            throw ValidationException::withMessages([
                'installment' => 'La cuota inicial no se puede modificar desde este flujo.',
            ]);
        }
    }

    private function assertWithinBounds(
        Carbon $newDue,
        ?Carbon $minExclusive,
        ?Carbon $maxExclusive,
        string $mode,
    ): void {
        $tooEarly = $minExclusive && $newDue->lte($minExclusive);
        $tooLate = $maxExclusive && $newDue->gte($maxExclusive);

        if (! $tooEarly && ! $tooLate) {
            return;
        }

        $minLabel = $minExclusive?->format('d/m/Y');
        $maxLabel = $maxExclusive?->format('d/m/Y');

        if ($mode === self::MODE_CASCADE) {
            $message = $minLabel
                ? "La fecha debe ser posterior al vencimiento de la cuota anterior ({$minLabel})."
                : 'La fecha queda fuera del rango permitido.';
        } elseif ($minLabel && $maxLabel) {
            $message = "La fecha debe estar estrictamente entre {$minLabel} y {$maxLabel}.";
        } elseif ($minLabel) {
            $message = "La fecha debe ser posterior al vencimiento de la cuota anterior ({$minLabel}).";
        } elseif ($maxLabel) {
            $message = "La fecha debe ser anterior al vencimiento de la cuota siguiente ({$maxLabel}).";
        } else {
            $message = 'La fecha queda fuera del rango permitido.';
        }

        throw ValidationException::withMessages([
            'due_date' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function logActivity(Contract $contract, AmortizationInstallment $installment, array $plan): void
    {
        $first = $plan['changes'][0] ?? null;
        $before = is_array($first) ? (string) $first['due_date_before'] : '';
        $after = is_array($first) ? (string) $first['due_date_after'] : '';
        $beforeLabel = $before !== '' ? Carbon::parse($before)->format('d/m/Y') : '--';
        $afterLabel = $after !== '' ? Carbon::parse($after)->format('d/m/Y') : '--';
        $number = (int) $plan['installment_number'];
        $count = (int) $plan['affected_count'];
        $mode = (string) $plan['mode'];
        $cadence = (string) ($plan['cadence'] ?? self::CADENCE_SAME_DAY);
        $cadenceLabel = $cadence === self::CADENCE_MONTH_END ? 'fin de mes' : 'mismo día del mes';

        $description = $mode === self::MODE_CASCADE
            ? sprintf(
                'Ajustó vencimientos en cascada (%s) desde la cuota %d (%s → %s); %d cuota(s) afectada(s)',
                $cadenceLabel,
                $number,
                $beforeLabel,
                $afterLabel,
                $count,
            )
            : sprintf(
                'Cambió la fecha de vencimiento de la cuota %d de %s a %s',
                $number,
                $beforeLabel,
                $afterLabel,
            );

        $activity = activity()
            ->performedOn($contract)
            ->withProperties([
                'mode' => $mode,
                'cadence' => $cadence,
                'installment_number' => $number,
                'affected_count' => $count,
                'promises_affected_count' => (int) $plan['promises_affected_count'],
                'updates_contract_anchor' => (bool) $plan['updates_contract_anchor'],
                'before' => ['due_date' => $before],
                'after' => ['due_date' => $after],
            ]);

        if (auth()->user()) {
            $activity->causedBy(auth()->user());
        }

        $activity->log($description);
    }
}
