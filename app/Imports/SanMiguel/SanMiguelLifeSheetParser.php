<?php

namespace App\Imports\SanMiguel;

use App\Enums\PaymentMethod;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee los libros de hoja de vida de San Miguel (uno por rango de diez lotes).
 *
 * La hoja de vida es la fuente de los pagos reales: es donde el equipo anota
 * cada recibo. No trae fechas de vencimiento, solo fechas de pago.
 */
class SanMiguelLifeSheetParser
{
    /**
     * Los libros llegan con nombres irregulares ("SAN MIGUEL HV 41-50.xlsx",
     * "SAN_MIGUEL_HV_41-50.xlsx"), así que se detectan por patrón.
     */
    private const FILENAME_PATTERN = '/^SAN[ _]MIGUEL[ _]HV[ _]\d+-\d+\.xlsx$/i';

    /** El número de lote sale del nombre de la hoja; la celda B4 viene mal en 10 hojas. */
    private const SHEET_PATTERN = '/^LOTE\s*V?\s*(\d+)\s*$/i';

    private const MAX_HEADER_SCAN = 32;

    private const BLANK_ROWS_BEFORE_STOP = 25;

    /** El proyecto arrancó en 2024; cualquier año fuera de este rango es un dedazo. */
    private const MIN_YEAR = 2020;

    private const MAX_YEAR = 2040;

    /**
     * @return list<string> rutas absolutas de los libros de hoja de vida
     */
    public function discover(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (preg_match(self::FILENAME_PATTERN, $entry)) {
                $files[] = rtrim($directory, '/').'/'.$entry;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return array<string, SanMiguelLifeSheet> indexado por número de lote
     */
    public function parse(array $files): array
    {
        $sheets = [];

        foreach ($files as $file) {
            foreach ($this->parseFile($file) as $lotNumber => $sheet) {
                $sheets[$lotNumber] = $sheet;
            }
        }

        return $sheets;
    }

    /**
     * @return array<string, SanMiguelLifeSheet>
     */
    private function parseFile(string $file): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($file);
        $sheets = [];

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            if (! preg_match(self::SHEET_PATTERN, trim($sheet->getTitle()), $match)) {
                continue;
            }

            $lotNumber = ltrim($match[1], '0') ?: '0';
            $sheets[$lotNumber] = $this->parseSheet($sheet, $lotNumber, basename($file));
        }

        $workbook->disconnectWorksheets();

        return $sheets;
    }

    private function parseSheet(Worksheet $sheet, string $lotNumber, string $fileName): SanMiguelLifeSheet
    {
        $issues = [];
        $title = trim($sheet->getTitle());

        $declared = $this->text($sheet, 'B4');
        if ($declared !== '' && $declared !== $lotNumber) {
            $issues[] = sprintf('la celda B4 dice "%s" y la hoja se llama "%s"; se usa el nombre de la hoja.', $declared, $title);
        }

        $columns = $this->findPaymentColumns($sheet);
        $rows = [];
        if ($columns === null) {
            $issues[] = 'no se encontró el encabezado FECHA/CONCEPTO del historial de pagos.';
        } else {
            [$rows, $rowIssues] = $this->readRows($sheet, $columns);
            $issues = array_merge($issues, $rowIssues);
        }

        $term = $this->label($sheet, 'PLAZO');

        return new SanMiguelLifeSheet(
            lotNumber: $lotNumber,
            sheetName: $title,
            fileName: $fileName,
            financedValue: $this->labelMoney($sheet, 'VALOR LOTE FINANCIADO'),
            downPaymentPactada: $this->labelMoney($sheet, 'VR CUOTA INICIAL'),
            installmentValue: $this->labelMoney($sheet, 'VR CUOTA'),
            termMonths: is_numeric($term) ? (int) $term : null,
            clientName: $this->label($sheet, 'CLIENTE'),
            clientDocument: $this->label($sheet, 'NIT'),
            address: $this->nullableLabel($sheet, 'DIRECCION'),
            email: $this->nullableLabel($sheet, 'CORREO'),
            phone: $this->nullableLabel($sheet, 'CELULAR'),
            advisor: $this->nullableLabel($sheet, 'VENDIDO POR'),
            rows: $rows,
            issues: $issues,
        );
    }

    /**
     * @return array<string, string>|null letras de columna por concepto, más '__row'
     */
    private function findPaymentColumns(Worksheet $sheet): ?array
    {
        $highest = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 20);

        for ($row = 1; $row <= self::MAX_HEADER_SCAN; $row++) {
            $map = [];
            for ($col = 1; $col <= $highest; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $key = $this->headerKey($this->normalize($this->text($sheet, $letter.$row)));
                if ($key !== null && ! isset($map[$key])) {
                    $map[$key] = $letter;
                }
            }

            if (isset($map['fecha'], $map['concepto'])) {
                $map['__row'] = (string) $row;

                return $map;
            }
        }

        return null;
    }

    private function headerKey(string $header): ?string
    {
        return match (true) {
            $header === 'FECHA' => 'fecha',
            str_starts_with($header, 'CONCEPTO') => 'concepto',
            str_starts_with($header, 'RECIBO') => 'recibo',
            $header === 'EFECTIVO' => 'efectivo',
            str_contains($header, 'BANCOLOMBIA') => 'bancolombia',
            str_contains($header, 'OCCIDENTE') => 'occidente',
            str_contains($header, 'DAVIVIENDA') => 'davivienda',
            str_contains($header, 'AGRARIO') => 'agrario',
            str_contains($header, 'PERMUTA') => 'permuta',
            $header === 'SALDO' => 'saldo',
            default => null,
        };
    }

    /**
     * Cada columna de dinero implica una forma de pago distinta.
     *
     * @return array<string, PaymentMethod>
     */
    private function methodColumns(): array
    {
        return [
            'efectivo' => PaymentMethod::CASH,
            'bancolombia' => PaymentMethod::TRANSFER,
            'occidente' => PaymentMethod::BANK,
            'davivienda' => PaymentMethod::BANK,
            'agrario' => PaymentMethod::BANK,
            'permuta' => PaymentMethod::BARTER,
        ];
    }

    /**
     * @param  array<string, string>  $columns
     * @return array{0: list<SanMiguelLifeSheetRow>, 1: list<string>}
     */
    private function readRows(Worksheet $sheet, array $columns): array
    {
        $rows = [];
        $issues = [];
        $headerRow = (int) $columns['__row'];
        $last = (int) $sheet->getHighestDataRow();
        $blank = 0;

        for ($row = $headerRow + 1; $row <= $last; $row++) {
            $concept = $this->text($sheet, $columns['concepto'].$row);
            $rawDate = $this->text($sheet, $columns['fecha'].$row);

            if ($concept === '' && $rawDate === '') {
                if (++$blank > self::BLANK_ROWS_BEFORE_STOP) {
                    break;
                }

                continue;
            }
            $blank = 0;

            $upper = $this->normalize($concept);
            if (str_contains($upper, 'TOTAL') || str_contains($upper, 'SALDO INICIAL')) {
                continue;
            }

            [$amount, $method, $isTotalsRow] = $this->readAmount($sheet, $columns, $row);
            if ($isTotalsRow) {
                continue;
            }

            if (bccomp($amount, '0.00', 2) <= 0) {
                if ($concept !== '') {
                    $issues[] = sprintf('fila %d: "%s" no tiene monto en ninguna forma de pago.', $row, $concept);
                }

                continue;
            }

            $date = $this->readDate($sheet, $columns['fecha'].$row, $row, $issues);
            if ($date === null) {
                $issues[] = sprintf(
                    'fila %d: se omite el pago de %s (recibo %s) porque la fecha "%s" no es interpretable.',
                    $row,
                    $this->formatMoney($amount),
                    $this->text($sheet, ($columns['recibo'] ?? $columns['concepto']).$row) ?: 's/n',
                    $rawDate,
                );

                continue;
            }

            $receipt = isset($columns['recibo']) ? $this->text($sheet, $columns['recibo'].$row) : '';

            $rows[] = new SanMiguelLifeSheetRow(
                date: $date,
                amount: $amount,
                concept: $concept !== '' ? $concept : 'PAGO',
                receiptNumber: $receipt !== '' ? $receipt : null,
                paymentMethod: $method,
                excelRow: $row,
                excelSaldo: isset($columns['saldo'])
                    ? $this->money($sheet->getCell($columns['saldo'].$row)->getCalculatedValue())
                    : null,
            );
        }

        usort(
            $rows,
            fn (SanMiguelLifeSheetRow $a, SanMiguelLifeSheetRow $b) => $a->date <=> $b->date ?: $a->excelRow <=> $b->excelRow,
        );

        return [$rows, $issues];
    }

    /**
     * Suma las columnas de dinero de la fila. La forma de pago es la de la
     * columna con el mayor monto.
     *
     * @param  array<string, string>  $columns
     * @return array{0: string, 1: PaymentMethod, 2: bool}
     */
    private function readAmount(Worksheet $sheet, array $columns, int $row): array
    {
        $total = '0.00';
        $best = '0.00';
        $method = PaymentMethod::CASH;

        foreach ($this->methodColumns() as $key => $candidate) {
            if (! isset($columns[$key])) {
                continue;
            }

            $coordinate = $columns[$key].$row;

            // La fila de totales del pie trae =SUM(...) y no es un pago.
            $raw = $sheet->getCell($coordinate)->getValue();
            if (is_string($raw) && stripos($raw, 'SUM(') !== false) {
                return ['0.00', $method, true];
            }

            $amount = $this->money($sheet->getCell($coordinate)->getCalculatedValue());
            if (bccomp($amount, '0.00', 2) <= 0) {
                continue;
            }

            $total = bcadd($total, $amount, 2);
            if (bccomp($amount, $best, 2) === 1) {
                $best = $amount;
                $method = $candidate;
            }
        }

        return [$total, $method, false];
    }

    /**
     * @param  list<string>  $issues
     */
    private function readDate(Worksheet $sheet, string $coordinate, int $row, array &$issues): ?Carbon
    {
        $value = $sheet->getCell($coordinate)->getCalculatedValue();

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        $text = trim((string) (is_scalar($value) ? $value : ''));
        if ($text === '') {
            return null;
        }

        // Algunas filas juntan dos pagos y anotan las dos fechas ("7/04/2026-4/05/2026").
        $parts = preg_split('/\s*[-–]\s*/u', $text) ?: [$text];
        if (count($parts) > 1) {
            $issues[] = sprintf(
                'fila %d: la celda de fecha trae %d fechas ("%s"); se toma la primera y el monto queda unificado.',
                $row,
                count($parts),
                $text,
            );
        }

        return $this->parseDateText((string) $parts[0]);
    }

    private function parseDateText(string $text): ?Carbon
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Mes escrito con un cero de más ("15/012/2024" es diciembre de 2024).
        $text = preg_replace('#^(\d{1,2})/0(\d{2})/(\d{4})$#', '$1/$2/$3', $text) ?? $text;

        // El equipo mezcla el relleno con ceros entre día y mes ("7/04/2026",
        // "15/9/2025"), así que se prueban las cuatro combinaciones.
        $formats = ['d/m/Y', 'j/m/Y', 'd/n/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y-m-d'];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $text);
            if ($parsed === false || $parsed->format($format) !== $text) {
                continue;
            }

            // Un año fuera de rango delata un dedazo ("27/02/20325"), y no hay
            // forma segura de adivinar el correcto.
            $year = (int) $parsed->format('Y');
            if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
                return null;
            }

            return Carbon::instance($parsed)->startOfDay();
        }

        return null;
    }

    /**
     * Las etiquetas del encabezado se solapan: "VR CUOTA INICIAL" contiene
     * "VR CUOTA" y aparece antes, así que la coincidencia exacta manda y la
     * parcial queda de respaldo para etiquetas como "NIT / CC".
     */
    private function findLabelRow(Worksheet $sheet, string $needle): ?int
    {
        $partial = null;

        for ($row = 1; $row <= self::MAX_HEADER_SCAN; $row++) {
            $label = $this->normalize($this->text($sheet, 'A'.$row));
            if ($label === $needle) {
                return $row;
            }
            if ($partial === null && str_contains($label, $needle)) {
                $partial = $row;
            }
        }

        return $partial;
    }

    private function label(Worksheet $sheet, string $needle): string
    {
        $row = $this->findLabelRow($sheet, $needle);

        return $row === null ? '' : $this->text($sheet, 'B'.$row);
    }

    private function nullableLabel(Worksheet $sheet, string $needle): ?string
    {
        $value = $this->label($sheet, $needle);

        return $value !== '' ? $value : null;
    }

    private function labelMoney(Worksheet $sheet, string $needle): string
    {
        $row = $this->findLabelRow($sheet, $needle);

        return $row === null ? '0.00' : $this->money($sheet->getCell('B'.$row)->getCalculatedValue());
    }

    private function text(Worksheet $sheet, string $coordinate): string
    {
        $value = $sheet->getCell($coordinate)->getCalculatedValue();
        if (! is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function money(mixed $value): string
    {
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return '0.00';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtoupper(trim(str_replace(["\n", "\r"], ' ', $value)));
        $value = strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function formatMoney(string $value): string
    {
        return '$'.number_format((float) $value, 2, '.', ',');
    }
}
