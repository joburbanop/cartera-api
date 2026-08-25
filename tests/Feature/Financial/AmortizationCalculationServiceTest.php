<?php

use App\Models\Contract;
use App\Services\Financial\Amortization\AmortizationCalculationService;
use App\Enums\AmortizationStatusEnum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('calcula cuota fija correctamente con tasa de interés', function () {
    $service = new AmortizationCalculationService();
    
    // Préstamo de 100,000 a 12% anual por 12 meses
    $quota = $service->calculateFixedQuota('100000.00', '12.00', 12);
    
    // Cuota fija esperada: ~8884.88
    expect($quota)->toBeString();
    expect($quota)->toMatchRegex('/^\d+\.\d{2}$/');
    expect(bccomp($quota, '8884.00', 2))->toBeGreaterThanOrEqual(0);
    expect(bccomp($quota, '8886.00', 2))->toBeLessThanOrEqual(0);
});

it('maneja tasa de interés cero correctamente', function () {
    $service = new AmortizationCalculationService();
    
    // Préstamo de 120,000 sin interés a 12 meses
    $quota = $service->calculateFixedQuota('120000.00', '0.00', 12);
    
    expect($quota)->toEqual('10000.00');
});

it('retorna cero para plazo inválido', function () {
    $service = new AmortizationCalculationService();
    
    $quota = $service->calculateFixedQuota('100000.00', '12.00', 0);
    
    expect($quota)->toEqual('0.00');
});

it('genera tabla de amortización completa', function () {
    $contract = Contract::factory()->create([
        'sale_price' => 100000.00,
        'down_payment_pactada' => 20000.00,
        'interest_rate' => 12.00,
        'term_months' => 12,
        'start_date' => '2024-01-01',
    ]);

    $service = new AmortizationCalculationService();
    $schedule = $service->buildSchedule($contract);

    expect($schedule)->toHaveCount(12);
    expect($schedule->first()['installment_number'])->toBe(1);
    expect($schedule->last()['installment_number'])->toBe(12);
    
    // El saldo final debe ser cero
    expect($schedule->last()['remaining_balance'])->toEqual('0.00');
    
    // Todas las cuotas deben tener estado pendiente inicialmente
    $schedule->each(function ($row) {
        expect($row['status'])->toEqual(AmortizationStatusEnum::PENDING->value);
    });
});

it('ajusta la última cuota para cerrar saldo en cero', function () {
    $contract = Contract::factory()->create([
        'sale_price' => 50000.00,
        'down_payment_pactada' => 10000.00,
        'interest_rate' => 10.50,
        'term_months' => 24,
        'start_date' => '2024-01-15',
    ]);

    $service = new AmortizationCalculationService();
    $schedule = $service->buildSchedule($contract);

    $lastInstallment = $schedule->last();
    
    expect($lastInstallment['remaining_balance'])->toEqual('0.00');
    expect($lastInstallment['projected_balance'])->toEqual('0.00');
});

it('aplica pago total correctamente', function () {
    $service = new AmortizationCalculationService();
    
    $result = $service->applyPayment('1000.00', '1000.00');
    
    expect($result['amount_applied'])->toEqual('1000.00');
    expect($result['remaining_balance'])->toEqual('0.00');
    expect($result['status'])->toEqual(AmortizationStatusEnum::PAID->value);
    expect($result['surplus'] ?? '0.00')->toEqual('0.00');
});

it('aplica pago con excedente correctamente', function () {
    $service = new AmortizationCalculationService();
    
    $result = $service->applyPayment('800.00', '1000.00');
    
    expect($result['amount_applied'])->toEqual('800.00');
    expect($result['remaining_balance'])->toEqual('0.00');
    expect($result['status'])->toEqual(AmortizationStatusEnum::PAID->value);
    expect($result['surplus'])->toEqual('200.00');
});

it('aplica pago parcial correctamente', function () {
    $service = new AmortizationCalculationService();
    
    $result = $service->applyPayment('1000.00', '600.00');
    
    expect($result['amount_applied'])->toEqual('600.00');
    expect($result['remaining_balance'])->toEqual('400.00');
    expect($result['status'])->toEqual(AmortizationStatusEnum::PARTIAL->value);
    expect($result)->not->toHaveKey('surplus');
});

it('mantiene precisión decimal con BC Math', function () {
    $service = new AmortizationCalculationService();
    
    // Valores que causarían errores de punto flotante
    $quota = $service->calculateFixedQuota('33333.33', '9.99', 36);
    
    expect($quota)->toBeString();
    expect($quota)->toMatchRegex('/^\d+\.\d{2}$/');
    
    // Verificar que no hay errores de precisión
    $floatVal = (float) $quota;
    expect($floatVal)->toBeFloat();
    expect(number_format($floatVal, 2, '.', ''))->toEqual($quota);
});

it('calcula fechas de vencimiento correctas', function () {
    $contract = Contract::factory()->create([
        'sale_price' => 100000.00,
        'down_payment_pactada' => 20000.00,
        'interest_rate' => 12.00,
        'term_months' => 3,
        'first_installment_date' => '2024-01-31',
    ]);

    $service = new AmortizationCalculationService();
    $schedule = $service->buildSchedule($contract);

    // Enero 31 -> Febrero 29 (año bisiesto 2024) -> Marzo 31
    expect($schedule[0]['due_date'])->toEqual('2024-01-31');
    expect($schedule[1]['due_date'])->toEqual('2024-02-29');
    expect($schedule[2]['due_date'])->toEqual('2024-03-31');
});
