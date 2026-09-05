<?php

namespace App\Services\Imports;

use App\Enums\AmortizationStatus;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ExcelScheduleImportService
{
    public const LOTS = [
        '1', '2', '4', '5', '7', '8', '9', '11', '12', '13', '14', '15',
        '17', '18', '19', '21', '22', '23', '24', '25', '26', '27', '33',
        '34', '35', '36', '37', '38', '39', '40', '41', '42', '46', '47',
        '48', '49', '52', '53', '55', '56', '57', '58',
    ];

    public function __construct(
        private readonly TermReductionService $termReductionService,
        private readonly AmortizationCalculationService $amortizationCalculationService,
    ) {}

    public function workbookPath(): string
    {
        return base_path('app/imports/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx');
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>, imported: list<array<string, mixed>>, pending: int}
     */
    public function preview(string $lotNumber): array
    {
        $contract = $this->contract($lotNumber);
        $before = $this->snapshot($contract);

        DB::beginTransaction();
        try {
            $imported = $this->applyToContract($contract, $lotNumber);
            $after = $this->snapshot($contract->fresh());
            $pending = $contract->fresh()->amortizationInstallments()
                ->where('installment_number', '>', 0)
                ->where('status', AmortizationStatus::PENDING->value)
                ->count();
        } finally {
            DB::rollBack();
        }

        return [
            'before' => $before,
            'after' => $after,
            'imported' => $imported,
            'pending_generated' => $pending,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function apply(string $lotNumber): array
    {
        $contract = $this->contract($lotNumber);

        return DB::transaction(function () use ($contract, $lotNumber) {
            return $this->applyToContract($contract, $lotNumber);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function applyToContract(Contract $contract, string $lotNumber): array
    {
        $rows = $this->paidExcelRows($lotNumber);
        if ($rows === []) {
            if ($contract->amortizationInstallments()->where('installment_number', '>', 0)->doesntExist()) {
                $this->restoreUnpaidFrenchSchedule($contract);
            }

            return [];
        }

        $dates = $this->paymentDatesByReceipt($contract, $lotNumber);

        $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->delete();

        $imported = [];
        $number = 1;
        foreach ($rows as $row) {
            $paymentDate = $this->resolvePaymentDate($row['recibo_keys'], $row['recibo_raw'], $dates);
            $payload = [
                'installment_number' => $number,
                'due_date' => $row['nper'],
                'payment_date' => $paymentDate,
                'receipt_number' => $row['recibo_raw'],
                'installment_value' => $row['cuota'],
                'extra_payment' => $row['extra'],
                'interest_value' => $row['interes'],
                'principal_value' => $row['amort'],
                'interest_paid' => $row['interes'],
                'principal_paid' => $row['amort'],
                'quota_debt' => '0.00',
                'remaining_balance' => $row['saldo'],
                'projected_balance' => $row['saldo'],
                'status' => AmortizationStatus::PAID->value,
            ];
            $contract->amortizationInstallments()->create($payload);
            $imported[] = $payload + ['applied' => bcadd($row['cuota'], $row['extra'], 2)];
            $number++;
        }

        $last = $contract->amortizationInstallments()
            ->reorder()
            ->where('installment_number', '>', 0)
            ->orderByDesc('installment_number')
            ->first();

        if ($last && bccomp((string) $last->remaining_balance, '0.00', 2) > 0) {
            $this->termReductionService->recalculateFuturePlan($contract->fresh(), $last->fresh());
        }

        return $imported;
    }

    private function restoreUnpaidFrenchSchedule(Contract $contract): void
    {
        foreach ($this->amortizationCalculationService->buildSchedule($contract) as $row) {
            if ((int) $row['installment_number'] === 0) {
                continue;
            }

            $contract->amortizationInstallments()->create([
                'installment_number' => $row['installment_number'],
                'due_date' => $row['due_date'],
                'payment_date' => null,
                'installment_value' => $row['installment_value'],
                'extra_payment' => $row['extra_payment'],
                'interest_value' => $row['interest_value'],
                'principal_value' => $row['principal_value'],
                'quota_debt' => $row['quota_debt'],
                'remaining_balance' => $row['remaining_balance'],
                'projected_balance' => $row['projected_balance'],
                'status' => $row['status'],
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paidExcelRows(string $lotNumber): array
    {
        $path = $this->workbookPath();
        $title = 'LOTE '.$lotNumber;
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$title]);
        $workbook = $reader->load($path);
        $sheet = $workbook->getSheetByName($title);
        if (! $sheet) {
            throw new \RuntimeException("No existe la pestaña {$title}");
        }

        $headerRow = null;
        $cols = [];
        for ($row = 1; $row <= 15; $row++) {
            $map = [];
            for ($col = 1; $col <= 10; $col++) {
                $h = mb_strtoupper(trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getFormattedValue()));
                $h = strtr($h, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
                $h = preg_replace('/\s+/', ' ', $h) ?? $h;
                if ($h === 'RECIBO' || str_starts_with($h, 'RECIBO')) {
                    $map['recibo'] = $col;
                }
                if ($h === 'NPER') {
                    $map['nper'] = $col;
                }
                if ($h === 'CUOTA') {
                    $map['cuota'] = $col;
                }
                if (str_contains($h, 'ABONO EXTRA')) {
                    $map['extra'] = $col;
                }
                if ($h === 'INTERESES' || $h === 'INTERES') {
                    $map['interes'] = $col;
                }
                if (str_contains($h, 'AMORTIZA')) {
                    $map['amort'] = $col;
                }
                if ($h === 'SALDO') {
                    $map['saldo'] = $col;
                }
            }
            if (isset($map['recibo'], $map['nper'], $map['cuota'], $map['extra'], $map['interes'], $map['amort'], $map['saldo'])) {
                $headerRow = $row;
                $cols = $map;
                break;
            }
        }
        if ($headerRow === null) {
            throw new \RuntimeException("No se encontró la tabla izquierda en {$title}");
        }

        $rows = [];
        $max = (int) $sheet->getHighestRow();
        for ($row = $headerRow + 1; $row <= $max; $row++) {
            $recibo = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($cols['recibo']).$row)->getFormattedValue());
            if ($recibo === '') {
                continue;
            }
            $nper = $this->parseDate($sheet, Coordinate::stringFromColumnIndex($cols['nper']).$row);
            if ($nper === null) {
                continue;
            }
            $rows[] = [
                'recibo_raw' => $recibo,
                'recibo_keys' => $this->normalizeReceipts($recibo, $lotNumber),
                'nper' => $nper,
                'cuota' => $this->money($sheet->getCell(Coordinate::stringFromColumnIndex($cols['cuota']).$row)->getCalculatedValue()),
                'extra' => $this->money($sheet->getCell(Coordinate::stringFromColumnIndex($cols['extra']).$row)->getCalculatedValue()),
                'interes' => $this->money($sheet->getCell(Coordinate::stringFromColumnIndex($cols['interes']).$row)->getCalculatedValue()),
                'amort' => $this->money($sheet->getCell(Coordinate::stringFromColumnIndex($cols['amort']).$row)->getCalculatedValue()),
                'saldo' => $this->money($sheet->getCell(Coordinate::stringFromColumnIndex($cols['saldo']).$row)->getCalculatedValue()),
            ];
        }

        $workbook->disconnectWorksheets();

        usort($rows, fn ($a, $b) => $a['nper'] <=> $b['nper']);

        return $rows;
    }

    /**
     * @return array<string, string> receipt key => payment date
     */
    private function paymentDatesByReceipt(Contract $contract, string $lotNumber): array
    {
        $map = [];
        foreach ($contract->transactions()->orderBy('transaction_date')->orderBy('id')->get() as $tx) {
            $type = $tx->transaction_type instanceof TransactionType
                ? $tx->transaction_type->value
                : (string) $tx->transaction_type;
            if ($type === TransactionType::DOWN_PAYMENT->value) {
                continue;
            }
            if (! preg_match('/Recibo\s*#\s*([^\|]+)/iu', (string) $tx->notes, $match)) {
                continue;
            }
            foreach ($this->normalizeReceipts(trim($match[1]), $lotNumber) as $key) {
                $map[$key] = $tx->transaction_date->toDateString();
            }
        }

        if ($lotNumber === '46') {
            if (isset($map['678']) && ! isset($map['679'])) {
                $map['679'] = $map['678'];
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $dates
     */
    private function resolvePaymentDate(array $keys, string $raw, array $dates): ?string
    {
        $found = [];
        foreach ($keys as $key) {
            if (isset($dates[$key])) {
                $found[] = $dates[$key];
            }
        }
        if ($found === []) {
            return null;
        }
        sort($found);

        return $found[array_key_last($found)];
    }

    /**
     * @return list<string>
     */
    private function normalizeReceipts(string $raw, string $lotNumber): array
    {
        $raw = trim($raw);
        $parts = preg_split('/\s*(?:-|–|,|\/|;)\s*/u', $raw) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $digits = preg_replace('/\D+/', '', $part) ?? '';
            if ($digits === '') {
                continue;
            }
            $key = ltrim($digits, '0') ?: '0';
            $keys[] = $key;
            if ($lotNumber === '46' && $key === '679') {
                $keys[] = '678';
            }
        }

        return array_values(array_unique($keys));
    }

    private function parseDate($sheet, string $cell): ?string
    {
        $raw = $sheet->getCell($cell)->getValue();
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse(trim((string) $raw))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function money($value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Contract $contract): array
    {
        $paid = $contract->amortizationInstallments()
            ->where('installment_number', '>', 0)
            ->orderBy('installment_number')
            ->get();

        $byStatus = ['paid' => 0, 'partial' => 0, 'overdue' => 0, 'pending' => 0];
        foreach ($paid as $row) {
            $status = $row->status->value ?? (string) $row->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $importedLike = $paid->where('status', AmortizationStatus::PAID);
        $sumCuotaExtra = '0.00';
        foreach ($importedLike as $row) {
            $sumCuotaExtra = bcadd($sumCuotaExtra, bcadd((string) $row->installment_value, (string) $row->extra_payment, 2), 2);
        }

        return [
            'term_months' => (int) $contract->term_months,
            'n_gt_0' => $paid->count(),
            'paid' => $byStatus['paid'] ?? 0,
            'partial' => $byStatus['partial'] ?? 0,
            'pending' => $byStatus['pending'] ?? 0,
            'tx_sum' => bcadd((string) $contract->transactions()->sum('amount'), '0', 2),
            'regular_sum' => bcadd((string) $contract->transactions()->where('transaction_type', '!=', TransactionType::DOWN_PAYMENT)->sum('amount'), '0', 2),
            'sum_cuota_extra_paid' => $sumCuotaExtra,
            'rows' => $paid->where('status', AmortizationStatus::PAID)->values()->map(fn ($row) => [
                'n' => (int) $row->installment_number,
                'due' => $row->due_date?->toDateString(),
                'paid_on' => $row->payment_date instanceof \DateTimeInterface ? $row->payment_date->format('Y-m-d') : $row->payment_date,
                'recibo' => $row->receipt_number,
                'cuota' => (string) $row->installment_value,
                'extra' => (string) $row->extra_payment,
                'interes' => (string) $row->interest_paid,
                'amort' => (string) $row->principal_paid,
                'saldo' => (string) $row->remaining_balance,
            ])->all(),
        ];
    }

    private function contract(string $lotNumber): Contract
    {
        $contract = Contract::query()->where('contract_number', 'SM-LOTE-'.$lotNumber)->first();
        if (! $contract) {
            throw new \InvalidArgumentException("No existe SM-LOTE-{$lotNumber}");
        }

        return $contract;
    }
}
