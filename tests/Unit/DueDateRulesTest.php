<?php

use App\Support\DueDateRules;
use Carbon\Carbon;

it('considera vencida solo una fecha estrictamente anterior a hoy', function () {
    $hoy = '2026-09-07';

    expect(DueDateRules::isOverdue('2026-09-06', $hoy))->toBeTrue()
        ->and(DueDateRules::isOverdue('2026-09-07', $hoy))->toBeFalse()
        ->and(DueDateRules::isOverdue('2026-09-08', $hoy))->toBeFalse()
        ->and(DueDateRules::isOverdue(null, $hoy))->toBeTrue();
});

it('normaliza asOf al día calendario', function () {
    expect(DueDateRules::asOfDate(Carbon::parse('2026-09-07 23:59:59')))->toBe('2026-09-07');
});
