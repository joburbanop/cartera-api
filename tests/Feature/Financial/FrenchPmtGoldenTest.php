<?php

use App\Http\Requests\StoreContractRequest;
use App\Services\Financial\Amortization\AmortizationCalculationService;

it('calcula PMT francés según el vector dorado y el futuro es PMT × plazo', function () {
    $path = base_path('tests/fixtures/golden/french-pmt.json');
    $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $service = app(AmortizationCalculationService::class);

    expect($json['rounding'])->toBe('half_up_2');

    foreach ($json['cases'] as $case) {
        $pmt = $service->calculateFixedQuota(
            $case['principal'],
            $case['monthly_rate_percent'],
            $case['months'],
        );

        expect($pmt)->toBe($case['pmt']);

        $future = StoreContractRequest::calculateExpectedFutureValue(
            (float) $case['principal'],
            0.0,
            (float) $case['monthly_rate_percent'],
            $case['months'],
        );

        expect(bccomp(
            number_format($future, 2, '.', ''),
            bcmul($case['pmt'], (string) $case['months'], 2),
            2,
        ))->toBe(0);
    }
});
