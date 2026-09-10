<?php

use App\Support\ReceiptNumber;

it('normaliza comas y espacios al formato SM con guion', function () {
    expect(ReceiptNumber::normalize('0258, 0259, 0260'))->toBe('0258-0259-0260')
        ->and(ReceiptNumber::normalize('0258-0289'))->toBe('0258-0289')
        ->and(ReceiptNumber::normalize('  0258  '))->toBe('0258')
        ->and(ReceiptNumber::normalize(''))->toBeNull()
        ->and(ReceiptNumber::normalize(null))->toBeNull();
});

it('antepone Recibo #TOKEN en notes sin pisar el resto', function () {
    expect(ReceiptNumber::mergeIntoNotes(null, '0258-0289'))->toBe('Recibo #0258-0289')
        ->and(ReceiptNumber::mergeIntoNotes('Cobro de residuales menores acumulados', '0258'))
        ->toBe('Recibo #0258 | Cobro de residuales menores acumulados')
        ->and(ReceiptNumber::mergeIntoNotes('Recibo #0001 | Concepto: CUOTA 1', '0258-0289'))
        ->toBe('Recibo #0258-0289 | Concepto: CUOTA 1');
});

it('lee el token de la columna y, si falta, de notes históricas', function () {
    expect(ReceiptNumber::fromStored('0258-0289', 'Recibo #0001'))->toBe('0258-0289')
        ->and(ReceiptNumber::fromStored(null, 'Recibo #0349 | Concepto: CUOTA 1'))->toBe('0349')
        ->and(ReceiptNumber::fromStored('', 'Sin recibo'))->toBeNull();
});
