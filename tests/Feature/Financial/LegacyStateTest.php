<?php

use App\Support\Financial\LegacyState;
use App\Enums\AmortizationStatusEnum;

it('convierte estado legacy sin_pagar a PENDING', function () {
    $result = LegacyState::resolveToNewEnum('sin_pagar');
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('convierte estado legacy pending a PENDING', function () {
    $result = LegacyState::resolveToNewEnum('pending');
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('convierte estado legacy vencida a PENDING', function () {
    $result = LegacyState::resolveToNewEnum('vencida');
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('convierte estado legacy overdue a PENDING', function () {
    $result = LegacyState::resolveToNewEnum('overdue');
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('convierte estado legacy parcial a PARTIAL', function () {
    $result = LegacyState::resolveToNewEnum('parcial');
    expect($result)->toEqual(AmortizationStatusEnum::PARTIAL->value);
});

it('convierte estado legacy partial a PARTIAL', function () {
    $result = LegacyState::resolveToNewEnum('partial');
    expect($result)->toEqual(AmortizationStatusEnum::PARTIAL->value);
});

it('convierte estado legacy pagada a PAID', function () {
    $result = LegacyState::resolveToNewEnum('pagada');
    expect($result)->toEqual(AmortizationStatusEnum::PAID->value);
});

it('convierte estado legacy paid a PAID', function () {
    $result = LegacyState::resolveToNewEnum('paid');
    expect($result)->toEqual(AmortizationStatusEnum::PAID->value);
});

it('maneja null retornando PENDING por defecto', function () {
    $result = LegacyState::resolveToNewEnum(null);
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('maneja string vacío retornando PENDING por defecto', function () {
    $result = LegacyState::resolveToNewEnum('');
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('maneja estado desconocido retornando PENDING por defecto', function () {
    $result = LegacyState::resolveToNewEnum('estado_desconocido');
    expect($result)->toEqual(AmortizationStatusEnum::PENDING->value);
});

it('ignora mayúsculas y espacios en blanco', function () {
    expect(LegacyState::resolveToNewEnum('SIN_PAGAR'))->toEqual(AmortizationStatusEnum::PENDING->value);
    expect(LegacyState::resolveToNewEnum('  Pagada  '))->toEqual(AmortizationStatusEnum::PAID->value);
    expect(LegacyState::resolveToNewEnum('PARCIAL'))->toEqual(AmortizationStatusEnum::PARTIAL->value);
});

it('verifica equivalencia correctamente', function () {
    expect(LegacyState::isEquivalent('sin_pagar', AmortizationStatusEnum::PENDING))->toBeTrue();
    expect(LegacyState::isEquivalent('pagada', AmortizationStatusEnum::PAID))->toBeTrue();
    expect(LegacyState::isEquivalent('parcial', AmortizationStatusEnum::PARTIAL))->toBeTrue();
    expect(LegacyState::isEquivalent('pending', AmortizationStatusEnum::PAID))->toBeFalse();
});

it('obtiene equivalentes legacy para PENDING', function () {
    $equivalents = LegacyState::getLegacyEquivalents(AmortizationStatusEnum::PENDING);
    
    expect($equivalents)->toBeArray();
    expect($equivalents)->toContain('sin_pagar', 'pending', 'vencida', 'overdue');
});

it('obtiene equivalentes legacy para PARTIAL', function () {
    $equivalents = LegacyState::getLegacyEquivalents(AmortizationStatusEnum::PARTIAL);
    
    expect($equivalents)->toBeArray();
    expect($equivalents)->toContain('parcial', 'partial');
});

it('obtiene equivalentes legacy para PAID', function () {
    $equivalents = LegacyState::getLegacyEquivalents(AmortizationStatusEnum::PAID);
    
    expect($equivalents)->toBeArray();
    expect($equivalents)->toContain('pagada', 'paid');
});
