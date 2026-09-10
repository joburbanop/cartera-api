<?php

use App\Imports\SanMiguel\SanMiguelPaymentConceptParser;

it('expande rangos puros con guion y espacios', function (string $concept, array $numbers) {
    $parsed = (new SanMiguelPaymentConceptParser)->parse($concept);

    expect($parsed->kind)->toBe('range')
        ->and($parsed->numbers)->toBe($numbers)
        ->and($parsed->hasPlusAbono)->toBeFalse()
        ->and($parsed->leftoverOption)->toBe('reducir_plazo');
})->with([
    ['CUOTA 3-4', [3, 4]],
    ['CUOTA 1-2-3', [1, 2, 3]],
    ['CUOTA 5- 6 - 7', [5, 6, 7]],
    ['CUOTA 1 -2', [1, 2]],
    ['CUOTA 9- 10', [9, 10]],
    ['CUOTA 2-3-4-5-6-7', [2, 3, 4, 5, 6, 7]],
    ['CCUOTA 8-9-10', [8, 9, 10]],
]);

it('no tira el rango cuando el concepto también dice + ABONO', function (string $concept, array $numbers) {
    $parsed = (new SanMiguelPaymentConceptParser)->parse($concept);

    expect($parsed->kind)->toBe('range_plus_abono')
        ->and($parsed->numbers)->toBe($numbers)
        ->and($parsed->hasPlusAbono)->toBeTrue()
        ->and($parsed->leftoverOption)->toBe('reducir_plazo');
})->with([
    ['CUOTA 2 - 3 + ABONO CAPITAL', [2, 3]],
    ['CUOTA 3 - 4 + ABONO', [3, 4]],
]);

it('reconoce CUOTA N simple y N + ABONO', function () {
    $parser = new SanMiguelPaymentConceptParser;
    $single = $parser->parse('CUOTA 6');
    expect($single->kind)->toBe('single')
        ->and($single->numbers)->toBe([6])
        ->and($single->hasPlusAbono)->toBeFalse();

    $plus = $parser->parse('CUOTA 1 + ABONO CAPITAL');
    expect($plus->kind)->toBe('single_plus_abono')
        ->and($plus->numbers)->toBe([1])
        ->and($plus->hasPlusAbono)->toBeTrue();

    $typo = $parser->parse('COUTA 3');
    expect($typo->kind)->toBe('single')
        ->and($typo->numbers)->toBe([3]);
});

it('marca inicial y extrae el concepto desde notas de recibo', function () {
    $parser = new SanMiguelPaymentConceptParser;
    $inicial = $parser->parse('Recibo #0074 | Concepto: CUOTA INICIAL');
    expect($inicial->kind)->toBe('inicial')
        ->and($inicial->numbers)->toBe([])
        ->and($inicial->inicialFirst)->toBeTrue();

    $cuota1 = $parser->parse('Recibo #0258-0289 | Concepto: CUOTA 1');
    expect($cuota1->kind)->toBe('single')
        ->and($cuota1->numbers)->toBe([1])
        ->and($cuota1->inicialFirst)->toBeTrue();
});
