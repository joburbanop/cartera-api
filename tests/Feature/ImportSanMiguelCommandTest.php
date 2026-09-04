<?php

use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function sanMiguelFixturePath(): string
{
    $path = sys_get_temp_dir().'/san-miguel-import-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;

    $variable = $spreadsheet->getActiveSheet();
    $variable->setTitle('LOTE 7');
    $variable->setCellValue('C1', 'Modalidad');
    $variable->setCellValue('A5', 100000);
    $variable->setCellValue('D2', 20000);
    $variable->setCellValue('D4', 0.01);
    $variable->setCellValue('D5', 12);
    $variable->setCellValue('C7', 'CLIENTE:');
    $variable->setCellValue('D7', 'ANA EXISTENTE');
    $variable->setCellValue('C8', 'CEDULA:');
    $variable->setCellValue('D8', '900900900');
    $variable->setCellValue('C9', 'Nper');
    $variable->setCellValue('C11', '15/02/2025');
    $variable->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'L9');
    $variable->setCellValue('L11', '10/01/2025');
    $variable->setCellValue('M11', 'CUOTA INICIAL');
    $variable->setCellValue('N11', 'R-1');
    $variable->setCellValue('O11', 20000);
    $variable->setCellValue('S11', 20000);
    $variable->setCellValue('U11', 80000);
    $variable->setCellValue('L12', '10/02/2025');
    $variable->setCellValue('M12', 'CUOTA 1');
    $variable->setCellValue('N12', 'R-2');
    $variable->setCellValue('Q12', 5000);
    $variable->setCellValue('S12', 5000);
    $variable->setCellValue('U12', 75000);
    $variable->setCellValue('V12', 'Abono en Occidente');

    $special = $spreadsheet->createSheet();
    $special->setTitle('LOTE 30');
    $special->setCellValue('A1', 'LOTE 30  –  Hameth Smith  –  LOTE ESPECIAL (sin tabla de amortización)');
    $special->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'A3');
    $special->setCellValue('B4', 'SALDO INICIAL (valor lote financiado)');
    $special->setCellValue('J4', 40000);
    $special->setCellValue('A5', '19/11/2024');
    $special->setCellValue('B5', 'ABONO');
    $special->setCellValue('C5', '057');
    $special->setCellValue('E5', 15000);
    $special->setCellValue('H5', 15000);
    $special->setCellValue('J5', 25000);
    $special->setCellValue('K5', 'Primer abono');

    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

beforeEach(function () {
    User::factory()->create();
    Project::query()->create([
        'name' => 'Proyecto San Miguel',
        'description' => 'Fixture',
        'location' => 'San Miguel',
        'status' => 'active',
    ]);
    Customer::factory()->create([
        'name' => 'ANA EXISTENTE',
        'document_number' => '900900900',
        'document_type' => 'CC',
    ]);
});

it('en dry-run no escribe contratos ni pagos y reporta crear vs reutilizar', function () {
    $path = sanMiguelFixturePath();

    $this->artisan('import:san-miguel', ['archivo' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Cuota variable: 1')
        ->expectsOutputToContain('Lotes especiales: 1')
        ->expectsOutputToContain('Clientes a crear: 1')
        ->expectsOutputToContain('Clientes a reutilizar: 1')
        ->assertSuccessful();

    expect(Contract::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(Lot::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(1);

    unlink($path);
});

it('importa un lote variable y uno especial usando los servicios reales', function () {
    $path = sanMiguelFixturePath();

    $this->artisan('import:san-miguel', ['archivo' => $path])
        ->expectsOutputToContain('Lotes importados: 2')
        ->assertSuccessful();

    $variable = Contract::query()->where('contract_number', 'SM-LOTE-7')->firstOrFail();
    expect($variable->is_special_lot)->toBeFalse()
        ->and((float) $variable->sale_price)->toBe(100000.0)
        ->and((float) $variable->down_payment_pactada)->toBe(20000.0)
        ->and((float) $variable->interest_rate)->toBe(1.0)
        ->and($variable->start_date->toDateString())->toBe('2025-01-15')
        ->and($variable->first_installment_date->toDateString())->toBe('2025-02-15')
        ->and($variable->customers)->toHaveCount(1)
        ->and($variable->status)->toBe(ContractStatus::ACTIVO);

    $inicial = $variable->transactions()->where('transaction_type', TransactionType::DOWN_PAYMENT)->firstOrFail();
    $cuota = $variable->transactions()->where('transaction_type', TransactionType::REGULAR_PAYMENT)->firstOrFail();

    expect($inicial->payment_method)->toBe(PaymentMethod::CASH)
        ->and($inicial->notes)->toContain('Recibo #R-1')
        ->and($cuota->payment_method)->toBe(PaymentMethod::BANK)
        ->and($cuota->notes)->toContain('Abono en Occidente')
        ->and($variable->lot->status)->toBe(LotStatus::VENDIDO);

    $special = Contract::query()->where('contract_number', 'SM-LOTE-30')->firstOrFail();
    expect($special->is_special_lot)->toBeTrue()
        ->and((int) $special->term_months)->toBe(0)
        ->and($special->transactions)->toHaveCount(1)
        ->and($special->transactions->first()->notes)->toContain('Primer abono')
        ->and($special->customers->first()->name)->toBe('Hameth Smith');

    unlink($path);
});

it('registra pagos históricos aunque la obligación ya esté cumplida', function () {
    $path = sys_get_temp_dir().'/san-miguel-paid-off-'.uniqid().'.xlsx';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('LOTE 9');
    $sheet->setCellValue('C1', 'Modalidad');
    $sheet->setCellValue('A5', 100000);
    $sheet->setCellValue('D2', 20000);
    $sheet->setCellValue('D4', 0.01);
    $sheet->setCellValue('D5', 12);
    $sheet->setCellValue('C7', 'CLIENTE:');
    $sheet->setCellValue('D7', 'ANA EXISTENTE');
    $sheet->setCellValue('C8', 'CEDULA:');
    $sheet->setCellValue('D8', '900900900');
    $sheet->setCellValue('C9', 'Nper');
    $sheet->setCellValue('C11', '15/02/2025');
    $sheet->fromArray([
        'FECHA', 'CONCEPTO', 'RECIBO #', 'EFECTIVO', 'BANCOLOMBIA', 'OCCIDENTE 6391', 'VALOR SIN CUENTA', 'TOTAL PAGO', 'APLICA A CUOTAS', 'SALDO', 'OBSERVACIÓN',
    ], null, 'L9');
    $sheet->setCellValue('L11', '10/01/2025');
    $sheet->setCellValue('M11', 'CUOTA INICIAL');
    $sheet->setCellValue('O11', 20000);
    $sheet->setCellValue('S11', 20000);
    $sheet->setCellValue('L12', '10/02/2025');
    $sheet->setCellValue('M12', 'CUOTA 1 + ABONO');
    $sheet->setCellValue('O12', 90000);
    $sheet->setCellValue('S12', 90000);
    $sheet->setCellValue('L13', '10/03/2025');
    $sheet->setCellValue('M13', 'CUOTA 2');
    $sheet->setCellValue('O13', 5000);
    $sheet->setCellValue('S13', 5000);
    (new Xlsx($spreadsheet))->save($path);

    $this->artisan('import:san-miguel', [
        'archivo' => $path,
        '--solo-lote' => '9',
    ])->assertSuccessful();

    $contract = Contract::query()->where('contract_number', 'SM-LOTE-9')->firstOrFail();
    $unapplied = $contract->transactions()
        ->where('notes', 'like', '%obligación ya estaba cumplida%')
        ->firstOrFail();

    expect((float) $unapplied->amount)->toBe(5000.0)
        ->and($contract->transactions()->count())->toBe(3);

    unlink($path);
});

it('con --solo-lote importa únicamente esa pestaña', function () {
    $path = sanMiguelFixturePath();

    $this->artisan('import:san-miguel', [
        'archivo' => $path,
        '--solo-lote' => '30',
    ])->assertSuccessful();

    expect(Contract::query()->where('contract_number', 'SM-LOTE-30')->exists())->toBeTrue()
        ->and(Contract::query()->where('contract_number', 'SM-LOTE-7')->exists())->toBeFalse();

    unlink($path);
});
