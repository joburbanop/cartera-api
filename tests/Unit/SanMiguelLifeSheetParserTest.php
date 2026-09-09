<?php

use App\Enums\PaymentMethod;
use App\Imports\SanMiguel\SanMiguelLifeSheetParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reproduce el encabezado real de la hoja de vida: etiqueta en A, valor en B,
 * con "VR CUOTA INICIAL" antes de "VR CUOTA".
 */
function lifeSheetHeader(Worksheet $sheet, string $declaredLot, string $client, string $document): void
{
    $sheet->setCellValue('B2', 'LOTE DE VIVIENDA');
    $sheet->setCellValue('B3', $declaredLot);
    $sheet->setCellValue('B4', $declaredLot);
    $sheet->setCellValue('A7', 'VALOR LOTE FINANCIADO');
    $sheet->setCellValue('B7', 209206574);
    $sheet->setCellValue('A9', 'VR CUOTA INICIAL');
    $sheet->setCellValue('B9', 16077970);
    $sheet->setCellValue('A10', 'VR CUOTA');
    $sheet->setCellValue('B10', 3218810);
    $sheet->setCellValue('A11', 'CLIENTE');
    $sheet->setCellValue('B11', $client);
    $sheet->setCellValue('A12', 'NIT / CC');
    $sheet->setCellValue('B12', $document);
    $sheet->setCellValue('A13', 'DIRECCION');
    $sheet->setCellValue('B13', 'CARRERA 18G# 7D-60');
    $sheet->setCellValue('A14', 'CORREO');
    $sheet->setCellValue('B14', 'titular@example.com');
    $sheet->setCellValue('A15', 'CELULAR');
    $sheet->setCellValue('B15', '3233308340');
    $sheet->setCellValue('A16', 'PLAZO');
    $sheet->setCellValue('B16', 60);
    $sheet->setCellValue('A17', 'VENDIDO POR');
    $sheet->setCellValue('B17', 'ASESOR UNO');

    $sheet->fromArray(
        ['FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'SALDO'],
        null,
        'A20',
    );
}

function lifeSheetWorkbookPath(callable $build, string $name = 'SAN MIGUEL HV 1-10.xlsx'): string
{
    $dir = sys_get_temp_dir().'/hv-'.uniqid();
    mkdir($dir);
    $spreadsheet = new Spreadsheet;
    $build($spreadsheet);
    (new Xlsx($spreadsheet))->save($dir.'/'.$name);

    return $dir.'/'.$name;
}

it('lee el encabezado y los pagos de una hoja de vida', function () {
    $path = lifeSheetWorkbookPath(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('LOTE V 1');
        lifeSheetHeader($sheet, '1', 'RAFAEL EDUARDO CRUZ MOYA', '80260496');

        $sheet->setCellValue('A21', '10/01/2025');
        $sheet->setCellValue('B21', 'CUOTA INICIAL');
        $sheet->setCellValue('C21', '0101');
        $sheet->setCellValue('D21', 5000000);
        $sheet->setCellValue('G21', 4000000);

        $sheet->setCellValue('A22', '7/02/2025');
        $sheet->setCellValue('B22', 'CUOTA 1');
        $sheet->setCellValue('C22', '0102');
        $sheet->setCellValue('E22', 1000000);
        $sheet->setCellValue('G22', 3000000);
    });

    $parser = new SanMiguelLifeSheetParser;
    $sheets = $parser->parse([$path]);

    expect($sheets)->toHaveKey('1');

    $hv = $sheets['1'];
    expect($hv->lotNumber)->toBe('1')
        ->and($hv->sheetName)->toBe('LOTE V 1')
        ->and($hv->financedValue)->toBe('209206574.00')
        ->and($hv->downPaymentPactada)->toBe('16077970.00')
        // La etiqueta "VR CUOTA" no debe confundirse con "VR CUOTA INICIAL".
        ->and($hv->installmentValue)->toBe('3218810.00')
        ->and($hv->termMonths)->toBe(60)
        ->and($hv->clientName)->toBe('RAFAEL EDUARDO CRUZ MOYA')
        ->and($hv->clientDocument)->toBe('80260496')
        ->and($hv->address)->toBe('CARRERA 18G# 7D-60')
        ->and($hv->email)->toBe('titular@example.com')
        ->and($hv->phone)->toBe('3233308340')
        ->and($hv->advisor)->toBe('ASESOR UNO')
        ->and($hv->issues)->toBe([])
        ->and($hv->rows)->toHaveCount(2)
        ->and($hv->sumPayments())->toBe('6000000.00');

    [$first, $second] = $hv->rows;
    expect($first->date->toDateString())->toBe('2025-01-10')
        ->and($first->amount)->toBe('5000000.00')
        ->and($first->concept)->toBe('CUOTA INICIAL')
        ->and($first->receiptNumber)->toBe('0101')
        ->and($first->paymentMethod)->toBe(PaymentMethod::CASH)
        ->and($first->excelSaldo)->toBe('4000000.00')
        // "7/02/2025" mezcla día sin cero y mes con cero.
        ->and($second->date->toDateString())->toBe('2025-02-07')
        ->and($second->paymentMethod)->toBe(PaymentMethod::TRANSFER);

    unlink($path);
});

it('descarta la fila de totales y las de saldo inicial', function () {
    $path = lifeSheetWorkbookPath(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('LOTE V 2');
        lifeSheetHeader($sheet, '2', 'CLIENTE DOS', '12345678');

        $sheet->setCellValue('A21', '10/01/2025');
        $sheet->setCellValue('B21', 'SALDO INICIAL');
        $sheet->setCellValue('D21', 99000000);

        $sheet->setCellValue('A22', '15/01/2025');
        $sheet->setCellValue('B22', 'CUOTA INICIAL');
        $sheet->setCellValue('D22', 2000000);

        $sheet->setCellValue('B23', 'TOTAL PAGADO');
        $sheet->setCellValue('D23', '=SUM(D21:D22)');
    });

    $hv = (new SanMiguelLifeSheetParser)->parse([$path])['2'];

    expect($hv->rows)->toHaveCount(1)
        ->and($hv->sumPayments())->toBe('2000000.00');

    unlink($path);
});

it('omite el pago cuya fecha no es interpretable y lo reporta', function () {
    $path = lifeSheetWorkbookPath(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('LOTE V 3');
        lifeSheetHeader($sheet, '3', 'CLIENTE TRES', '12345678');

        $sheet->setCellValue('A21', '27/02/20325');
        $sheet->setCellValue('B21', 'CUOTA 1');
        $sheet->setCellValue('C21', '0186');
        $sheet->setCellValue('D21', 2600000);
    });

    $hv = (new SanMiguelLifeSheetParser)->parse([$path])['3'];

    expect($hv->rows)->toBe([])
        ->and($hv->issues)->toHaveCount(1)
        ->and($hv->issues[0])->toContain('27/02/20325')
        ->and($hv->issues[0])->toContain('0186');

    unlink($path);
});

it('toma la primera fecha cuando la celda trae dos y avisa', function () {
    $path = lifeSheetWorkbookPath(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('LOTE V 4');
        lifeSheetHeader($sheet, '4', 'CLIENTE CUATRO', '12345678');

        $sheet->setCellValue('A21', '7/04/2026-4/05/2026');
        $sheet->setCellValue('B21', 'CUOTA 6 Y 7');
        $sheet->setCellValue('D21', 3000000);
    });

    $hv = (new SanMiguelLifeSheetParser)->parse([$path])['4'];

    expect($hv->rows)->toHaveCount(1)
        ->and($hv->rows[0]->date->toDateString())->toBe('2026-04-07')
        ->and($hv->rows[0]->amount)->toBe('3000000.00')
        ->and($hv->issues[0])->toContain('2 fechas');

    unlink($path);
});

it('prefiere el nombre de la hoja sobre el número de lote de B4 y lo reporta', function () {
    $path = lifeSheetWorkbookPath(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('LOTE V 53');
        lifeSheetHeader($sheet, '56 /53', 'CLIENTE CINCUENTA Y TRES', '12345678');

        $sheet->setCellValue('A21', '10/01/2025');
        $sheet->setCellValue('B21', 'CUOTA INICIAL');
        $sheet->setCellValue('D21', 1000000);
    });

    $sheets = (new SanMiguelLifeSheetParser)->parse([$path]);

    expect($sheets)->toHaveKey('53')
        ->and($sheets['53']->issues[0])->toContain('B4 dice "56 /53"');

    unlink($path);
});

it('suma las formas de pago de una misma fila y usa la mayor como método', function () {
    $path = lifeSheetWorkbookPath(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('LOTE V 5');
        lifeSheetHeader($sheet, '5', 'CLIENTE CINCO', '12345678');

        $sheet->setCellValue('A21', '10/01/2025');
        $sheet->setCellValue('B21', 'CUOTA INICIAL');
        $sheet->setCellValue('D21', 400000);
        $sheet->setCellValue('F21', 900000);
    });

    $row = (new SanMiguelLifeSheetParser)->parse([$path])['5']->rows[0];

    expect($row->amount)->toBe('1300000.00')
        ->and($row->paymentMethod)->toBe(PaymentMethod::BANK);

    unlink($path);
});

it('descubre los libros de hoja de vida por patrón de nombre', function () {
    $dir = sys_get_temp_dir().'/hv-discover-'.uniqid();
    mkdir($dir);
    foreach ([
        'SAN MIGUEL HV 1-10.xlsx',
        'SAN_MIGUEL_HV_41-50.xlsx',
        'SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx',
        'notas.xlsx',
    ] as $name) {
        touch($dir.'/'.$name);
    }

    $found = array_map('basename', (new SanMiguelLifeSheetParser)->discover($dir));

    expect($found)->toBe(['SAN MIGUEL HV 1-10.xlsx', 'SAN_MIGUEL_HV_41-50.xlsx']);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
