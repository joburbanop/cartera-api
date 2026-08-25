<?php

use App\Services\Financial\Amortization\AmortizationCalculationService;

it('calculates a fixed quota using bcmath without float drift', function () {
    $service = app(AmortizationCalculationService::class);

    $principal = '1000000.00';
    $quota = $service->calculateFixedQuota($principal, '1.00', 12);

    expect(preg_match('/^\d+\.\d{2}$/', $quota))->toBe(1)
        ->and(bccomp($quota, '88848.79', 2))->toBe(0);
});
