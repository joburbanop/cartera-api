<?php

use App\Enums\LotStatus;

it('conserva el valor interno abogado y solo cambia la etiqueta visible', function () {
    expect(LotStatus::ABOGADO->value)->toBe('abogado');
    expect(LotStatus::ABOGADO->name)->toBe('ABOGADO');
    expect(LotStatus::ABOGADO->label())->toBe('Renegociación');
});
