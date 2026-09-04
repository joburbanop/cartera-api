<?php

use App\Enums\PaymentMethod;
use App\Imports\SanMiguel\SanMiguelWorkbookParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

it('clasifica cuota variable y lote especial y extrae titulares y pagos', function () {
    $path = sys_get_temp_dir().'/san-miguel-parser-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;

    $variable = $spreadsheet->getActiveSheet();
    $variable->setTitle('LOTE 1');
    $variable->setCellValue('C1', 'Modalidad');
    $variable->setCellValue('A4', 'VR LOTE');
    $variable->setCellValue('A5', 100000);
    $variable->setCellValue('C2', 'Cuota inicial');
    $variable->setCellValue('D2', 20000);
    $variable->setCellValue('C4', 'Tasa');
    $variable->setCellValue('D4', 0.01);
    $variable->getStyle('D4')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE);
    $variable->setCellValue('C5', 'Plazo');
    $variable->setCellValue('D5', 12);
    $variable->setCellValue('C7', 'CLIENTE:');
    $variable->setCellValue('D7', 'ANA PEREZ - LUIS GOMEZ');
    $variable->setCellValue('C8', 'CEDULA:');
    $variable->setCellValue('D8', '11111111  -  22222222');
    $variable->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'L9');
    $variable->setCellValue('M10', 'SALDO INICIAL (valor lote financiado)');
    $variable->setCellValue('U10', 100000);
    $variable->setCellValue('L11', '15/01/2025');
    $variable->setCellValue('M11', 'CUOTA INICIAL');
    $variable->setCellValue('N11', '001');
    $variable->setCellValue('O11', 20000);
    $variable->setCellValue('S11', 20000);
    $variable->setCellValue('U11', 80000);
    $variable->setCellValue('L12', '15/02/2025');
    $variable->setCellValue('M12', 'CUOTA 1');
    $variable->setCellValue('N12', '002');
    $variable->setCellValue('P12', 10000);
    $variable->setCellValue('S12', 10000);
    $variable->setCellValue('U12', 70000);
    $variable->setCellValue('V12', 'Pago en sucursal');
    $variable->setCellValue('M13', 'TOTAL PAGADO');
    $variable->setCellValue('S13', 30000);
    $variable->setCellValue('U13', 70000);

    $special = $spreadsheet->createSheet();
    $special->setTitle('LOTE 30');
    $special->setCellValue('A1', 'LOTE 30  –  Hameth Smith Murillo Becerra  –  LOTE ESPECIAL (sin tabla de amortización)');
    $special->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'A3');
    $special->setCellValue('B4', 'SALDO INICIAL (valor lote financiado)');
    $special->setCellValue('J4', 90000);
    $special->setCellValue('A5', '19/11/2024');
    $special->setCellValue('B5', 'ABONO');
    $special->setCellValue('C5', '057');
    $special->setCellValue('E5', 10000);
    $special->setCellValue('H5', 10000);
    $special->setCellValue('J5', 80000);
    $special->setCellValue('B6', 'TOTAL PAGADO');
    $special->setCellValue('H6', 10000);
    $special->setCellValue('J6', 80000);

    (new Xlsx($spreadsheet))->save($path);

    $lots = (new SanMiguelWorkbookParser)->parse($path);
    unlink($path);

    expect($lots)->toHaveCount(2);

    $variableLot = $lots[0];
    expect($variableLot->kind)->toBe('variable')
        ->and($variableLot->salePrice)->toBe('100000.00')
        ->and($variableLot->downPaymentPactada)->toBe('20000.00')
        ->and($variableLot->interestRate)->toBe(1.0)
        ->and($variableLot->termMonths)->toBe(12)
        ->and($variableLot->clients)->toHaveCount(2)
        ->and($variableLot->clients[0]->documentNumber)->toBe('11111111')
        ->and($variableLot->clients[1]->name)->toBe('LUIS GOMEZ')
        ->and($variableLot->payments)->toHaveCount(2)
        ->and($variableLot->payments[0]->isDownPayment)->toBeTrue()
        ->and($variableLot->payments[0]->paymentMethod)->toBe(PaymentMethod::CASH)
        ->and($variableLot->payments[1]->isDownPayment)->toBeFalse()
        ->and($variableLot->payments[1]->paymentMethod)->toBe(PaymentMethod::TRANSFER)
        ->and($variableLot->payments[1]->observation)->toBe('Pago en sucursal')
        ->and($variableLot->saldoMatches)->toBeTrue();

    $specialLot = $lots[1];
    expect($specialLot->kind)->toBe('especial')
        ->and($specialLot->isSpecialLot)->toBeTrue()
        ->and($specialLot->salePrice)->toBe('90000.00')
        ->and($specialLot->clients[0]->name)->toBe('Hameth Smith Murillo Becerra')
        ->and($specialLot->clients[0]->documentMissing)->toBeTrue()
        ->and($specialLot->payments)->toHaveCount(1)
        ->and($specialLot->payments[0]->isDownPayment)->toBeTrue()
        ->and($specialLot->payments[0]->paymentMethod)->toBe(PaymentMethod::TRANSFER)
        ->and($specialLot->saldoMatches)->toBeTrue();
});

it('separa cotitulares por salto de línea y cédulas unidas con guion', function () {
    $path = sys_get_temp_dir().'/san-miguel-nl-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('LOTE 15');
    $sheet->setCellValue('C1', 'Modalidad');
    $sheet->setCellValue('A5', 100000);
    $sheet->setCellValue('D2', 10000);
    $sheet->setCellValue('D4', 0.01);
    $sheet->setCellValue('D5', 12);
    $sheet->setCellValue('C7', 'CLIENTE:');
    $sheet->setCellValue('D7', "ALEX FAUSTINO NAZARIT SALCEDO- \nHERNAN ELIAS NAZARIT SALCEDO ");
    $sheet->setCellValue('C8', 'CEDULA:');
    $sheet->setCellValue('D8', '16915958-94535317');
    $sheet->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'L9');
    $sheet->setCellValue('L11', '15/01/2025');
    $sheet->setCellValue('M11', 'CUOTA INICIAL');
    $sheet->setCellValue('O11', 10000);
    $sheet->setCellValue('S11', 10000);
    $sheet->setCellValue('U11', 999999);

    (new Xlsx($spreadsheet))->save($path);
    $lots = (new SanMiguelWorkbookParser)->parse($path);
    unlink($path);

    expect($lots[0]->clients)->toHaveCount(2)
        ->and($lots[0]->clients[0]->name)->toBe('ALEX FAUSTINO NAZARIT SALCEDO')
        ->and($lots[0]->clients[0]->documentNumber)->toBe('16915958')
        ->and($lots[0]->clients[1]->name)->toBe('HERNAN ELIAS NAZARIT SALCEDO')
        ->and($lots[0]->clients[1]->documentNumber)->toBe('94535317')
        ->and($lots[0]->issues)->not->toContain(
            'Saldo final del Excel (999,999.00) no cuadra con precio - suma de pagos (100,000.00 − 10,000.00 = 90,000.00). Delta 909,999.00 (tolerancia $10).'
        );
});

it('marca inconsistencia en cuota variable solo si la suma de pagos supera el precio', function () {
    $path = sys_get_temp_dir().'/san-miguel-overpay-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('LOTE 2');
    $sheet->setCellValue('C1', 'Modalidad');
    $sheet->setCellValue('A5', 1000);
    $sheet->setCellValue('D2', 100);
    $sheet->setCellValue('D4', 0.01);
    $sheet->setCellValue('D5', 12);
    $sheet->setCellValue('C7', 'CLIENTE:');
    $sheet->setCellValue('D7', 'SOLO UNO');
    $sheet->setCellValue('C8', 'CEDULA:');
    $sheet->setCellValue('D8', '12345678');
    $sheet->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'L9');
    $sheet->setCellValue('L11', '15/01/2025');
    $sheet->setCellValue('M11', 'CUOTA 1');
    $sheet->setCellValue('S11', 5000);
    $sheet->setCellValue('U11', 0);

    (new Xlsx($spreadsheet))->save($path);
    $lots = (new SanMiguelWorkbookParser)->parse($path);
    unlink($path);

    expect($lots[0]->saldoMatches)->toBeFalse()
        ->and($lots[0]->issues[0])->toContain('supera el precio de venta');
});
