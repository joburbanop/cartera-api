<?php

use App\Support\AmortizationScheduleDisplay;

it('pinta intereses y amortización según el vector dorado', function () {
    $path = dirname(__DIR__).'/fixtures/golden/amortization-display.json';
    $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    foreach ($json['cases'] as $case) {
        $row = (object) $case;

        expect(AmortizationScheduleDisplay::interestAmount($row))
            ->toBe((float) $case['expected_interest'])
            ->and(AmortizationScheduleDisplay::amortizationAmount($row))
            ->toBe((float) $case['expected_amortization']);
    }
});
