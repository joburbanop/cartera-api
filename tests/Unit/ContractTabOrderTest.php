<?php

use App\Support\ContractTabOrder;

it('completa ids faltantes y descarta desconocidos', function () {
    expect(ContractTabOrder::normalize(['hoja-vida', 'inventado', 'hoja-vida', 'amortizacion']))
        ->toBe([
            'hoja-vida',
            'amortizacion',
            'promesa',
            'bitacora-contrato',
            'bitacora-cliente',
        ]);
});

it('muestra solo las pestañas disponibles respetando el orden guardado', function () {
    $saved = [
        'hoja-vida',
        'bitacora-contrato',
        'promesa',
        'amortizacion',
        'bitacora-cliente',
    ];

    expect(ContractTabOrder::visible($saved, ['amortizacion', 'hoja-vida', 'bitacora-contrato']))
        ->toBe(['hoja-vida', 'bitacora-contrato', 'amortizacion']);
});

it('reordena las visibles y deja las ocultas en su sitio', function () {
    $full = ContractTabOrder::DEFAULT;
    $newVisible = ['hoja-vida', 'bitacora-contrato', 'amortizacion'];

    expect(ContractTabOrder::applyVisibleReorder($full, $newVisible))->toBe([
        'hoja-vida',
        'bitacora-contrato',
        'promesa',
        'amortizacion',
        'bitacora-cliente',
    ]);
});
