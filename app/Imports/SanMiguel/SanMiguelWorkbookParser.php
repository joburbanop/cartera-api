<?php

namespace App\Imports\SanMiguel;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Support\FinancialRules;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SanMiguelWorkbookParser
{
    private const SALDO_TOLERANCE = 10.0;

    /**
     * @param  array<string, SanMiguelLifeSheet>  $lifeSheets  pagos reales, indexados por lote
     * @return list<SanMiguelParsedLot>
     */
    public function parse(string $path, array $lifeSheets = []): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException("No se encontró el archivo: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $workbook = $reader->load($path);
        $lots = [];
        $seen = [];

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $title = trim((string) $sheet->getTitle());
            if (! preg_match('/^LOTE\s+(\d+)$/i', $title, $match)) {
                continue;
            }

            $seen[$match[1]] = true;
            $lots[] = $this->parseSheet($sheet, $title, $match[1], $lifeSheets[$match[1]] ?? null);
        }

        // Hay lotes que se vendieron y se cobraron pero nunca tuvieron pestaña en
        // el libro de amortización: solo existen en la hoja de vida. Entran como
        // lotes especiales, que es la figura para cobros sin plan de cuotas.
        // Una hoja de vida sin pagos no alcanza para armar un contrato.
        foreach ($lifeSheets as $lotNumber => $lifeSheet) {
            if (isset($seen[$lotNumber]) || $lifeSheet->rows === []) {
                continue;
            }

            $lots[] = $this->lotFromLifeSheet($lifeSheet);
        }

        usort($lots, fn (SanMiguelParsedLot $a, SanMiguelParsedLot $b) => (int) $a->lotNumber <=> (int) $b->lotNumber);

        return $lots;
    }

    /**
     * El libro de amortización no trae cédula de los titulares de lote especial
     * (su encabezado es texto libre), así que quedaban con un documento
     * sintético. La hoja de vida sí la trae, y con el mismo nombre: se usa para
     * llenar ese vacío. Sin esto, un titular con dos lotes —uno especial y otro
     * que solo existe en la hoja de vida— entraría como dos clientes distintos.
     *
     * @param  list<SanMiguelParsedClient>  $clients
     * @param  list<string>  $issues
     * @return list<SanMiguelParsedClient>
     */
    private function fillMissingDocuments(array $clients, ?SanMiguelLifeSheet $lifeSheet, array &$issues): array
    {
        $document = trim((string) ($lifeSheet->clientDocument ?? ''));
        if ($document === '') {
            return $clients;
        }

        $hvName = $this->nameKey((string) $lifeSheet->clientName);

        foreach ($clients as $index => $client) {
            if (! $client->documentMissing || $this->nameKey($client->name) !== $hvName) {
                continue;
            }

            $clients[$index] = new SanMiguelParsedClient(
                name: $client->name,
                documentNumber: $document,
                documentType: $this->inferDocumentType($document),
                documentMissing: false,
            );
            $issues[] = sprintf(
                'La cédula de %s (%s) se tomó de la hoja de vida; el libro de amortización no la trae.',
                $client->name,
                $document,
            );
        }

        return $clients;
    }

    private function nameKey(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        return preg_replace('/[^a-z]/', '', mb_strtolower($ascii === false ? $name : $ascii)) ?? '';
    }

    /**
     * Lote que solo existe en la hoja de vida: sin plazo ni tasa, todo lo
     * recibido se abona contra el valor financiado.
     */
    private function lotFromLifeSheet(SanMiguelLifeSheet $lifeSheet): SanMiguelParsedLot
    {
        $issues = ['El lote no tiene pestaña en el libro de amortización; se arma desde la hoja de vida como lote especial.'];
        foreach ($lifeSheet->issues as $issue) {
            $issues[] = 'Hoja de vida: '.$issue;
        }

        $salePrice = $lifeSheet->financedValue;
        if (bccomp($salePrice, '0.00', 2) <= 0) {
            $issues[] = 'La hoja de vida no trae valor lote financiado.';
        }

        $clients = $this->pairClients($lifeSheet->clientName, $lifeSheet->clientDocument, $issues);

        return $this->lot(
            $lifeSheet->sheetName,
            $lifeSheet->lotNumber,
            'especial',
            $salePrice,
            $salePrice,
            0.0,
            0,
            true,
            $clients,
            $this->paymentsFromLifeSheet($lifeSheet, true),
            $issues,
            null,
            $lifeSheet,
        );
    }

    private function parseSheet(
        Worksheet $sheet,
        string $sheetName,
        string $lotNumber,
        ?SanMiguelLifeSheet $lifeSheet,
    ): SanMiguelParsedLot {
        $a1 = $this->cellString($sheet, 'A1');
        $isSpecial = str_contains(mb_strtoupper($a1), 'LOTE ESPECIAL');

        return $isSpecial
            ? $this->parseSpecialLot($sheet, $sheetName, $lotNumber, $a1, $lifeSheet)
            : $this->parseVariableLot($sheet, $sheetName, $lotNumber, $lifeSheet);
    }

    private function parseVariableLot(
        Worksheet $sheet,
        string $sheetName,
        string $lotNumber,
        ?SanMiguelLifeSheet $lifeSheet,
    ): SanMiguelParsedLot {
        $issues = [];
        $salePrice = $this->moneyString($sheet->getCell('A5')->getCalculatedValue());
        $downPayment = $this->moneyString($sheet->getCell('D2')->getCalculatedValue());
        $interestRate = $this->percentAsSystemRate($sheet->getCell('D4')->getCalculatedValue());
        $termMonths = (int) round((float) ($sheet->getCell('D5')->getCalculatedValue() ?? 0));
        $interestRate = FinancialRules::effectiveInterestRate($termMonths, $interestRate);

        if (bccomp($salePrice, '0.00', 2) <= 0) {
            $issues[] = 'VR LOTE (A5) vacío o cero.';
        }
        if (bccomp($downPayment, '0.00', 2) < 0) {
            $issues[] = 'Cuota inicial (D2) inválida.';
        }
        if ($termMonths <= 0) {
            $issues[] = 'Plazo (D5) vacío o cero.';
        }

        $nameSource = $this->clientCell($sheet, 'C7', 'D7');
        $docSource = $this->clientCell($sheet, 'C8', 'D8');
        $clients = $this->fillMissingDocuments(
            $this->pairClients($nameSource, $docSource, $issues),
            $lifeSheet,
            $issues,
        );

        $payments = $this->resolvePayments($sheet, $lifeSheet, false, $issues);

        return $this->lot(
            $sheetName,
            $lotNumber,
            'variable',
            $salePrice,
            $downPayment,
            $interestRate,
            $termMonths,
            false,
            $clients,
            $payments,
            $issues,
            $this->findFirstNperDate($sheet),
            $lifeSheet,
        );
    }

    /**
     * Los pagos reales salen de la hoja de vida. El historial del libro de
     * amortización queda solo como respaldo para lotes que no la tengan.
     *
     * @param  list<string>  $issues
     * @return list<SanMiguelParsedPayment>
     */
    private function resolvePayments(
        Worksheet $sheet,
        ?SanMiguelLifeSheet $lifeSheet,
        bool $allAreDownPayment,
        array &$issues,
    ): array {
        if ($lifeSheet !== null) {
            foreach ($lifeSheet->issues as $issue) {
                $issues[] = 'Hoja de vida: '.$issue;
            }

            return $this->paymentsFromLifeSheet($lifeSheet, $allAreDownPayment);
        }

        $columns = $this->findHistoryColumns($sheet, ! $allAreDownPayment);
        if ($columns === null) {
            $issues[] = 'No se encontró el encabezado del historial de pagos (FECHA/CONCEPTO).';

            return [];
        }

        return $this->readPayments($sheet, $columns, $allAreDownPayment, $issues);
    }

    /**
     * @return list<SanMiguelParsedPayment>
     */
    private function paymentsFromLifeSheet(SanMiguelLifeSheet $lifeSheet, bool $allAreDownPayment): array
    {
        $payments = [];

        foreach ($lifeSheet->rows as $row) {
            $payments[] = new SanMiguelParsedPayment(
                date: $row->date->copy(),
                amount: $row->amount,
                concept: $row->concept,
                receiptNumber: $row->receiptNumber,
                paymentMethod: $row->paymentMethod,
                observation: null,
                excelSaldo: $row->excelSaldo,
                isDownPayment: $allAreDownPayment || str_contains(mb_strtoupper($row->concept), 'INICIAL'),
                excelRow: $row->excelRow,
                collectionOption: $this->collectionOption($row->concept),
            );
        }

        return $payments;
    }

    private function parseSpecialLot(
        Worksheet $sheet,
        string $sheetName,
        string $lotNumber,
        string $a1,
        ?SanMiguelLifeSheet $lifeSheet,
    ): SanMiguelParsedLot {
        $issues = [];
        $nameChunk = $this->specialLotClientChunk($a1);
        $nameParts = $this->splitNameList($nameChunk);
        $clients = [];
        foreach ($nameParts as $name) {
            $clients[] = new SanMiguelParsedClient(
                name: $name,
                documentNumber: null,
                documentType: DocumentType::CC,
                documentMissing: true,
            );
        }
        if ($clients === []) {
            $issues[] = 'No se pudo extraer el nombre del cliente desde A1.';
        }
        $clients = $this->fillMissingDocuments($clients, $lifeSheet, $issues);

        $columns = $this->findHistoryColumns($sheet, false);
        $salePrice = $columns !== null ? $this->findInitialBalance($sheet, $columns) : '0.00';

        // El saldo inicial del lote especial es el valor lote financiado, que la
        // hoja de vida también trae en su encabezado.
        if (bccomp($salePrice, '0.00', 2) <= 0 && $lifeSheet !== null) {
            $salePrice = $lifeSheet->financedValue;
        }
        if (bccomp($salePrice, '0.00', 2) <= 0) {
            $issues[] = 'SALDO INICIAL del lote especial vacío o cero.';
        }

        $payments = $this->resolvePayments($sheet, $lifeSheet, true, $issues);

        return $this->lot(
            $sheetName,
            $lotNumber,
            'especial',
            $salePrice,
            $salePrice,
            0.0,
            0,
            true,
            $clients,
            $payments,
            $issues,
            null,
            $lifeSheet,
        );
    }

    /**
     * @param  list<SanMiguelParsedClient>  $clients
     * @param  list<SanMiguelParsedPayment>  $payments
     * @param  list<string>  $issues
     */
    private function lot(
        string $sheetName,
        string $lotNumber,
        string $kind,
        string $salePrice,
        string $downPayment,
        float $interestRate,
        int $termMonths,
        bool $isSpecial,
        array $clients,
        array $payments,
        array $issues,
        ?Carbon $firstNperDate = null,
        ?SanMiguelLifeSheet $lifeSheet = null,
    ): SanMiguelParsedLot {
        $sum = '0.00';
        foreach ($payments as $payment) {
            $sum = bcadd($sum, $payment->amount, 2);
        }

        $lastExcelSaldo = $payments === [] ? null : $payments[array_key_last($payments)]->excelSaldo;
        $expectedSaldo = bcsub($salePrice, $sum, 2);
        $delta = $lastExcelSaldo === null
            ? $expectedSaldo
            : bcsub($lastExcelSaldo, $expectedSaldo, 2);
        $overpaid = bccomp($sum, bcadd($salePrice, number_format(self::SALDO_TOLERANCE, 2, '.', ''), 2), 2) === 1;
        $matches = $isSpecial
            ? ($lastExcelSaldo !== null && abs((float) $delta) <= self::SALDO_TOLERANCE)
            : ! $overpaid;

        if ($payments === []) {
            $issues[] = 'No hay filas de pago en el historial.';
        }

        if ($overpaid) {
            $issues[] = sprintf(
                'La suma de pagos (%s) supera el precio de venta (%s).',
                $this->formatMoney($sum),
                $this->formatMoney($salePrice),
            );
        }

        if ($isSpecial && $lastExcelSaldo !== null && ! $matches) {
            $issues[] = sprintf(
                'Saldo final del Excel (%s) no cuadra con precio - suma de abonos (%s − %s = %s). Delta %s (tolerancia $10).',
                $this->formatMoney($lastExcelSaldo),
                $this->formatMoney($salePrice),
                $this->formatMoney($sum),
                $this->formatMoney($expectedSaldo),
                $this->formatMoney($delta),
            );
        }

        $inicialSum = '0.00';
        foreach ($payments as $payment) {
            if ($payment->isDownPayment) {
                $inicialSum = bcadd($inicialSum, $payment->amount, 2);
            }
        }
        if (! $isSpecial && bccomp($inicialSum, $downPayment, 2) === 1) {
            $issues[] = sprintf(
                'La suma de conceptos INICIAL (%s) supera la cuota inicial pactada (%s).',
                $this->formatMoney($inicialSum),
                $this->formatMoney($downPayment),
            );
        }

        usort($payments, fn (SanMiguelParsedPayment $a, SanMiguelParsedPayment $b) => $a->date <=> $b->date ?: $a->excelRow <=> $b->excelRow);

        return new SanMiguelParsedLot(
            sheetName: $sheetName,
            lotNumber: $lotNumber,
            kind: $kind,
            salePrice: $salePrice,
            downPaymentPactada: $downPayment,
            interestRate: $interestRate,
            termMonths: $termMonths,
            isSpecialLot: $isSpecial,
            clients: $clients,
            payments: $payments,
            lastExcelSaldo: $lastExcelSaldo,
            sumPayments: $sum,
            expectedSaldo: $expectedSaldo,
            saldoMatches: $matches,
            saldoDelta: $delta,
            issues: $issues,
            firstNperDate: $firstNperDate,
            lifeSheet: $lifeSheet,
        );
    }

    private function clientCell(Worksheet $sheet, string $labelCell, string $valueCell): string
    {
        $label = $this->cellString($sheet, $labelCell);
        $value = $this->cellString($sheet, $valueCell);
        if ($value !== '' && ! preg_match('/^(cliente|cedula|cédula)\b/i', $value)) {
            return $value;
        }
        if ($label !== '' && ! preg_match('/^(cliente|cedula|cédula)\s*:?\s*$/i', $label)) {
            return $label;
        }

        return $value;
    }

    /**
     * @param  list<string>  $issues
     * @return list<SanMiguelParsedClient>
     */
    private function pairClients(string $namesRaw, string $docsRaw, array &$issues): array
    {
        $names = $this->splitNameList($namesRaw);
        $docs = $this->splitDocumentList($docsRaw);

        if ($names === []) {
            $issues[] = 'Nombre de cliente vacío.';
        }
        if ($docs === []) {
            $issues[] = 'Cédula de cliente vacía.';
        }
        if ($names !== [] && $docs !== [] && count($names) !== count($docs)) {
            $issues[] = sprintf(
                'Cantidad de nombres (%d) distinta de cédulas (%d).',
                count($names),
                count($docs),
            );
        }

        $count = max(count($names), count($docs));
        $clients = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? ('Titular '.($i + 1));
            $doc = $docs[$i] ?? null;
            $clients[] = new SanMiguelParsedClient(
                name: $name,
                documentNumber: $doc,
                documentType: $this->inferDocumentType($doc),
                documentMissing: $doc === null || $doc === '',
            );
        }

        return $clients;
    }

    /**
     * @return list<string>
     */
    private function splitNameList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = $this->splitHolderChunks($raw);

        return array_values(array_filter(array_map(
            fn ($part) => trim($part, " \t-"),
            $parts,
        )));
    }

    /**
     * @return list<string>
     */
    private function splitDocumentList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = $this->splitHolderChunks($raw, splitDigitHyphen: true);
        $docs = [];
        foreach ($parts as $part) {
            $digits = preg_replace('/[^\dA-Za-z]/', '', trim($part)) ?? '';
            if ($digits !== '') {
                $docs[] = $digits;
            }
        }

        return $docs;
    }

    /**
     * @return list<string>
     */
    private function splitHolderChunks(string $raw, bool $splitDigitHyphen = false): array
    {
        $normalized = preg_replace("/\r\n|\r/", "\n", $raw) ?? $raw;
        $pattern = $splitDigitHyphen
            ? '/\s*-\s*\n|\n|\s+-\s+|(?<=\d{5})-(?=\d{5})/u'
            : '/\s*-\s*\n|\n|\s+-\s+/u';

        return preg_split($pattern, $normalized) ?: [];
    }

    private function inferDocumentType(?string $document): DocumentType
    {
        if ($document === null || $document === '') {
            return DocumentType::CC;
        }
        if (preg_match('/[A-Za-z]/', $document)) {
            return DocumentType::PASSPORT;
        }

        return DocumentType::CC;
    }

    private function specialLotClientChunk(string $a1): string
    {
        $parts = preg_split('/\s*[–—]\s*/u', $a1) ?: [];
        $parts = array_values(array_filter(array_map(fn ($part) => trim($part), $parts)));
        foreach ($parts as $part) {
            if (preg_match('/^LOTE\s+\d+/i', $part) || str_contains(mb_strtoupper($part), 'LOTE ESPECIAL')) {
                continue;
            }

            return $part;
        }

        return '';
    }

    /**
     * @return array<string, int>|null
     */
    private function findHistoryColumns(Worksheet $sheet, bool $preferRightBlock): ?array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $scanMax = min(30, (int) $sheet->getHighestRow());

        for ($row = 1; $row <= $scanMax; $row++) {
            $map = [];
            for ($col = 1; $col <= $highestColumn; $col++) {
                $header = $this->normalizeHeader((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getFormattedValue());
                if ($header === '') {
                    continue;
                }
                $key = $this->headerKey($header);
                if ($key !== null) {
                    $map[$key] = $col;
                }
            }

            if (isset($map['fecha'], $map['concepto'], $map['total'])) {
                $fechaCol = $map['fecha'];
                if ($preferRightBlock && $fechaCol < 12 && $this->hasRightHistoryBlock($sheet, $row)) {
                    continue;
                }

                return $map;
            }
        }

        return null;
    }

    private function hasRightHistoryBlock(Worksheet $sheet, int $row): bool
    {
        for ($col = 12; $col <= 22; $col++) {
            $header = $this->normalizeHeader((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getFormattedValue());
            if ($this->headerKey($header) === 'fecha') {
                return true;
            }
        }

        return false;
    }

    private function findFirstNperDate(Worksheet $sheet): ?Carbon
    {
        $headerRow = null;
        $scanMax = min(40, (int) $sheet->getHighestRow());

        for ($row = 1; $row <= $scanMax; $row++) {
            $header = $this->normalizeHeader((string) $sheet->getCell('C'.$row)->getFormattedValue());
            if ($header === 'NPER') {
                $headerRow = $row;
                break;
            }
        }

        if ($headerRow === null) {
            return null;
        }

        $highest = min($headerRow + 30, (int) $sheet->getHighestRow());
        for ($row = $headerRow + 1; $row <= $highest; $row++) {
            $date = $this->parseDate($sheet, 'C'.$row);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function collectionOption(string $concept): ?string
    {
        $upper = mb_strtoupper($concept);

        if (str_contains($upper, 'ABONO EXTRA')
            || preg_match('/\+\s*ABONO/u', $upper)
            || preg_match('/CUOTA\s+\d+\s*\+/u', $upper)
        ) {
            return 'reducir_plazo';
        }

        if (preg_match('/CUOTA\s*\d+\s*(?:,|\-|Y|Y)\s*\d+/u', $upper)
            || preg_match('/CUOTA\s+\d+(\s*,\s*\d+){1,}/u', $upper)
            || preg_match('/CUOTA\s+\d+\s*-\s*\d+/u', $upper)
        ) {
            return null;
        }

        return null;
    }

    private function headerKey(string $header): ?string
    {
        return match (true) {
            $header === 'FECHA' => 'fecha',
            $header === 'CONCEPTO' => 'concepto',
            str_starts_with($header, 'RECIBO') => 'recibo',
            $header === 'EFECTIVO' => 'efectivo',
            str_contains($header, 'BANCOLOMBIA') => 'bancolombia',
            str_contains($header, 'OCCIDENTE') => 'occidente',
            str_contains($header, 'VALOR SIN CUENTA') => 'sin_cuenta',
            str_contains($header, 'TOTAL PAGO') => 'total',
            $header === 'SALDO' => 'saldo',
            str_contains($header, 'OBSERVACION') => 'observacion',
            default => null,
        };
    }

    private function findInitialBalance(Worksheet $sheet, array $columns): string
    {
        $conceptoCol = Coordinate::stringFromColumnIndex($columns['concepto']);
        $saldoCol = isset($columns['saldo']) ? Coordinate::stringFromColumnIndex($columns['saldo']) : null;
        $highest = (int) $sheet->getHighestRow();

        for ($row = 1; $row <= min($highest, 20); $row++) {
            $concept = mb_strtoupper($this->cellString($sheet, $conceptoCol.$row));
            if (str_contains($concept, 'SALDO INICIAL')) {
                if ($saldoCol) {
                    return $this->moneyString($sheet->getCell($saldoCol.$row)->getCalculatedValue());
                }
            }
        }

        return '0.00';
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $issues
     * @return list<SanMiguelParsedPayment>
     */
    private function readPayments(Worksheet $sheet, array $columns, bool $allAreDownPayment, array &$issues): array
    {
        $payments = [];
        $fechaCol = Coordinate::stringFromColumnIndex($columns['fecha']);
        $conceptoCol = Coordinate::stringFromColumnIndex($columns['concepto']);
        $totalCol = Coordinate::stringFromColumnIndex($columns['total']);
        $reciboCol = isset($columns['recibo']) ? Coordinate::stringFromColumnIndex($columns['recibo']) : null;
        $saldoCol = isset($columns['saldo']) ? Coordinate::stringFromColumnIndex($columns['saldo']) : null;
        $obsCol = isset($columns['observacion']) ? Coordinate::stringFromColumnIndex($columns['observacion']) : null;
        $highest = (int) $sheet->getHighestRow();

        for ($row = 1; $row <= $highest; $row++) {
            $concept = trim($this->cellString($sheet, $conceptoCol.$row));
            $conceptUpper = mb_strtoupper($concept);
            if ($conceptUpper === '' && $this->cellString($sheet, $fechaCol.$row) === '') {
                continue;
            }
            if (str_contains($conceptUpper, 'SALDO INICIAL') || str_contains($conceptUpper, 'TOTAL PAGADO')) {
                continue;
            }
            if (in_array($this->headerKey($this->normalizeHeader($concept)), ['fecha', 'concepto'], true)) {
                continue;
            }

            $date = $this->parseDate($sheet, $fechaCol.$row);
            $amount = $this->moneyString($sheet->getCell($totalCol.$row)->getCalculatedValue());

            if ($date === null && bccomp($amount, '0.00', 2) <= 0) {
                continue;
            }

            if ($date === null) {
                $issues[] = "Fila {$row}: pago sin FECHA válida (monto {$this->formatMoney($amount)}).";

                continue;
            }
            if (bccomp($amount, '0.00', 2) <= 0) {
                $issues[] = "Fila {$row}: fecha {$date->toDateString()} sin TOTAL PAGO.";

                continue;
            }

            $method = $this->resolvePaymentMethod($sheet, $columns, $row);
            $receipt = $reciboCol ? trim($this->cellString($sheet, $reciboCol.$row)) : null;
            $obs = $obsCol ? trim($this->cellString($sheet, $obsCol.$row)) : null;
            $excelSaldo = $saldoCol ? $this->moneyString($sheet->getCell($saldoCol.$row)->getCalculatedValue()) : null;
            $isInicial = str_contains($conceptUpper, 'INICIAL');

            $payments[] = new SanMiguelParsedPayment(
                date: $date,
                amount: $amount,
                concept: $concept !== '' ? $concept : 'PAGO',
                receiptNumber: $receipt !== '' ? $receipt : null,
                paymentMethod: $method,
                observation: $obs !== '' ? $obs : null,
                excelSaldo: $excelSaldo,
                isDownPayment: $allAreDownPayment || $isInicial,
                excelRow: $row,
                collectionOption: $this->collectionOption($concept),
            );
        }

        return $payments;
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function resolvePaymentMethod(Worksheet $sheet, array $columns, int $row): PaymentMethod
    {
        $candidates = [
            'efectivo' => PaymentMethod::CASH,
            'bancolombia' => PaymentMethod::TRANSFER,
            'occidente' => PaymentMethod::BANK,
            'sin_cuenta' => PaymentMethod::CASH,
        ];

        $bestAmount = '0.00';
        $best = PaymentMethod::CASH;

        foreach ($candidates as $key => $method) {
            if (! isset($columns[$key])) {
                continue;
            }
            $amount = $this->moneyString(
                $sheet->getCell(Coordinate::stringFromColumnIndex($columns[$key]).$row)->getCalculatedValue()
            );
            if (bccomp($amount, $bestAmount, 2) === 1) {
                $bestAmount = $amount;
                $best = $method;
            }
        }

        return $best;
    }

    private function parseDate(Worksheet $sheet, string $coordinate): ?Carbon
    {
        $cell = $sheet->getCell($coordinate);
        $value = $cell->getCalculatedValue();

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $text = trim((string) $cell->getFormattedValue());
        if ($text === '') {
            $text = trim((string) $value);
        }

        foreach (['d/m/Y', 'j/n/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);

                return $date?->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function percentAsSystemRate(mixed $value): float
    {
        $number = (float) ($value ?? 0);
        if ($number <= 1) {
            return round($number * 100, 4);
        }

        return round($number, 4);
    }

    private function moneyString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $raw = preg_replace('/[^\d,.\-]/', '', (string) $value) ?? '0';
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }

        if ($raw === '' || $raw === '-' || $raw === '.') {
            return '0.00';
        }

        return number_format((float) $raw, 2, '.', '');
    }

    private function cellString(Worksheet $sheet, string $coordinate): string
    {
        return trim((string) $sheet->getCell($coordinate)->getFormattedValue());
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtoupper(trim(str_replace(["\n", "\r"], ' ', $value)));
        $value = strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function formatMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }
}
