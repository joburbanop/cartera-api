<?php

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::factory()->create();
    Project::query()->create([
        'name' => 'Proyecto San Miguel',
        'description' => 'Fixture',
        'location' => 'San Miguel',
        'status' => 'active',
    ]);
});

/**
 * El libro de amortización y las hojas de vida tienen que vivir en el mismo
 * directorio, que es como los deja el equipo y como los busca el importador.
 * El libro va con nombre de fixture para que no se dispare la fase 2, que solo
 * tiene sentido sobre el libro oficial y sus lotes reales.
 */
function sanMiguelSourceDir(): string
{
    $dir = sys_get_temp_dir().'/san-miguel-src-'.uniqid();
    mkdir($dir);

    return $dir;
}

function removeSourceDir(string $dir): void
{
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
}

/**
 * Pestaña de cuota variable del libro de amortización, con su propio historial
 * de pagos (que la hoja de vida debe reemplazar cuando exista).
 */
function workbookVariableSheet(Worksheet $sheet, string $title): void
{
    $sheet->setTitle($title);
    $sheet->setCellValue('C1', 'Modalidad');
    $sheet->setCellValue('A5', 100000);
    $sheet->setCellValue('D2', 20000);
    $sheet->setCellValue('D4', 0.01);
    $sheet->setCellValue('D5', 12);
    $sheet->setCellValue('C7', 'CLIENTE:');
    $sheet->setCellValue('D7', 'ANA TITULAR');
    $sheet->setCellValue('C8', 'CEDULA:');
    $sheet->setCellValue('D8', '900900900');
    $sheet->setCellValue('C9', 'Nper');
    $sheet->setCellValue('C11', '15/02/2025');
    $sheet->fromArray(
        ['FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN'],
        null,
        'L9',
    );
    // Historial del libro: un solo pago, distinto del de la hoja de vida.
    $sheet->setCellValue('L11', '10/01/2025');
    $sheet->setCellValue('M11', 'CUOTA INICIAL');
    $sheet->setCellValue('N11', 'LIBRO-1');
    $sheet->setCellValue('O11', 20000);
    $sheet->setCellValue('S11', 20000);
    $sheet->setCellValue('U11', 80000);
}

/**
 * @param  list<array{date: string, concept: string, receipt: string, amount: int}>  $payments
 */
function lifeSheetTab(
    Worksheet $sheet,
    string $title,
    string $client,
    string $document,
    array $payments,
    int $financedValue = 120000,
): void {
    $sheet->setTitle($title);
    $sheet->setCellValue('B4', preg_replace('/\D/', '', $title));
    $sheet->setCellValue('A7', 'VALOR LOTE FINANCIADO');
    $sheet->setCellValue('B7', $financedValue);
    $sheet->setCellValue('A9', 'VR CUOTA INICIAL');
    $sheet->setCellValue('B9', 20000);
    $sheet->setCellValue('A10', 'VR CUOTA');
    $sheet->setCellValue('B10', 8000);
    $sheet->setCellValue('A11', 'CLIENTE');
    $sheet->setCellValue('B11', $client);
    $sheet->setCellValue('A12', 'NIT / CC');
    $sheet->setCellValue('B12', $document);
    $sheet->setCellValue('A16', 'PLAZO');
    $sheet->setCellValue('B16', 12);
    $sheet->fromArray(['FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'SALDO'], null, 'A20');

    $row = 21;
    foreach ($payments as $payment) {
        $sheet->setCellValue('A'.$row, $payment['date']);
        $sheet->setCellValue('B'.$row, $payment['concept']);
        $sheet->setCellValue('C'.$row, $payment['receipt']);
        $sheet->setCellValue('D'.$row, $payment['amount']);
        $row++;
    }
}

it('cuando hay hoja de vida los pagos salen de ahí y no del libro', function () {
    $dir = sanMiguelSourceDir();
    $workbookPath = $dir.'/SAN_MIGUEL_FIXTURE.xlsx';

    $book = new Spreadsheet;
    workbookVariableSheet($book->getActiveSheet(), 'LOTE 7');
    (new Xlsx($book))->save($workbookPath);

    $hv = new Spreadsheet;
    lifeSheetTab($hv->getActiveSheet(), 'LOTE V 7', 'ANA TITULAR', '900900900', [
        ['date' => '10/01/2025', 'concept' => 'CUOTA INICIAL', 'receipt' => 'HV-1', 'amount' => 20000],
        ['date' => '10/02/2025', 'concept' => 'CUOTA 1', 'receipt' => 'HV-2', 'amount' => 9000],
    ]);
    (new Xlsx($hv))->save($dir.'/SAN MIGUEL HV 1-10.xlsx');

    $this->artisan('import:san-miguel', ['archivo' => $workbookPath])
        ->expectsOutputToContain('desde hoja de vida: 1 lote(s)')
        ->expectsOutputToContain('desde libro de amortización: 0 lote(s)')
        ->assertSuccessful();

    $contract = Contract::query()->where('contract_number', 'SM-LOTE-7')->firstOrFail();
    $notes = $contract->transactions()->pluck('notes')->implode(' | ');

    expect($contract->transactions()->count())->toBe(2)
        ->and((float) $contract->transactions()->sum('amount'))->toBe(29000.0)
        ->and($notes)->toContain('HV-1')
        ->and($notes)->toContain('HV-2')
        ->and($notes)->not->toContain('LIBRO-1');

    removeSourceDir($dir);
});

it('sin hoja de vida cae al historial del libro de amortización', function () {
    $dir = sanMiguelSourceDir();
    $workbookPath = $dir.'/SAN_MIGUEL_FIXTURE.xlsx';

    $book = new Spreadsheet;
    workbookVariableSheet($book->getActiveSheet(), 'LOTE 7');
    (new Xlsx($book))->save($workbookPath);

    $this->artisan('import:san-miguel', ['archivo' => $workbookPath])
        ->expectsOutputToContain('desde libro de amortización: 1 lote(s)')
        ->assertSuccessful();

    $contract = Contract::query()->where('contract_number', 'SM-LOTE-7')->firstOrFail();

    expect($contract->transactions()->count())->toBe(1)
        ->and($contract->transactions()->first()->notes)->toContain('LIBRO-1');

    removeSourceDir($dir);
});

it('crea como lote especial el que solo existe en la hoja de vida', function () {
    $dir = sanMiguelSourceDir();
    $workbookPath = $dir.'/SAN_MIGUEL_FIXTURE.xlsx';

    $book = new Spreadsheet;
    workbookVariableSheet($book->getActiveSheet(), 'LOTE 7');
    (new Xlsx($book))->save($workbookPath);

    $hv = new Spreadsheet;
    lifeSheetTab($hv->getActiveSheet(), 'LOTE V 7', 'ANA TITULAR', '900900900', [
        ['date' => '10/01/2025', 'concept' => 'CUOTA INICIAL', 'receipt' => 'HV-1', 'amount' => 20000],
    ]);
    // El lote 28 nunca tuvo pestaña en el libro, pero sí recibos.
    lifeSheetTab($hv->createSheet(), 'LOTE V 28', 'CARLOS SIN PESTANA', '94303596', [
        ['date' => '05/03/2025', 'concept' => 'ABONO', 'receipt' => 'HV-9', 'amount' => 30000],
    ], financedValue: 90000);
    // El lote 10 tiene hoja pero sin un solo pago: no alcanza para un contrato.
    lifeSheetTab($hv->createSheet(), 'LOTE V 10', 'NADIE', '111', []);
    (new Xlsx($hv))->save($dir.'/SAN MIGUEL HV 1-10.xlsx');

    $this->artisan('import:san-miguel', ['archivo' => $workbookPath])
        ->expectsOutputToContain('Lotes importados: 2')
        ->assertSuccessful();

    $special = Contract::query()->where('contract_number', 'SM-LOTE-28')->firstOrFail();

    expect($special->is_special_lot)->toBeTrue()
        ->and((int) $special->term_months)->toBe(0)
        ->and((float) $special->interest_rate)->toBe(0.0)
        ->and((float) $special->sale_price)->toBe(90000.0)
        ->and($special->customers->first()->name)->toBe('CARLOS SIN PESTANA')
        ->and($special->customers->first()->document_number)->toBe('94303596')
        ->and($special->transactions()->count())->toBe(1)
        ->and(Contract::query()->where('contract_number', 'SM-LOTE-10')->exists())->toBeFalse();

    removeSourceDir($dir);
});

it('completa desde la hoja de vida la cédula que el libro no trae', function () {
    $dir = sanMiguelSourceDir();
    $workbookPath = $dir.'/SAN_MIGUEL_FIXTURE.xlsx';

    // El encabezado de lote especial es texto libre: trae nombre, nunca cédula.
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('LOTE 30');
    $sheet->setCellValue('A1', 'LOTE 30 – Hameth Smith – LOTE ESPECIAL (sin tabla de amortización)');
    $sheet->fromArray(
        ['FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN'],
        null,
        'A3',
    );
    $sheet->setCellValue('B4', 'SALDO INICIAL (valor lote financiado)');
    $sheet->setCellValue('J4', 40000);
    (new Xlsx($book))->save($workbookPath);

    $hv = new Spreadsheet;
    lifeSheetTab($hv->getActiveSheet(), 'LOTE V 30', 'Hameth Smith', '1013660700', [
        ['date' => '19/11/2024', 'concept' => 'ABONO', 'receipt' => 'HV-30', 'amount' => 15000],
    ], financedValue: 40000);
    (new Xlsx($hv))->save($dir.'/SAN MIGUEL HV 21-30.xlsx');

    $this->artisan('import:san-miguel', ['archivo' => $workbookPath])->assertSuccessful();

    $holder = Contract::query()->where('contract_number', 'SM-LOTE-30')->firstOrFail()->customers->first();

    expect($holder->document_number)->toBe('1013660700')
        ->and(Customer::query()->where('document_number', 'like', 'SM-NODNI-%')->count())->toBe(0);

    removeSourceDir($dir);
});
