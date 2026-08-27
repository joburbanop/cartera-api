<?php

use App\Http\Requests\StoreContractRequest;

it('calculates the future debt value using the PMT formula and tolerates rounding drift', function () {
    $salePrice = 120000000;
    $downPayment = 20000000;
    $interestRate = 1.5;
    $termMonths = 24;

    $expectedFutureValue = StoreContractRequest::calculateExpectedFutureValue(
        $salePrice,
        $downPayment,
        $interestRate,
        $termMonths,
    );

    expect($expectedFutureValue)->toBeGreaterThan(90000000)
        ->and($expectedFutureValue)->toBeFloat();

    expect(StoreContractRequest::calculateCustomPlanVariance($expectedFutureValue, $expectedFutureValue))->toBeLessThan(5)
        ->and(StoreContractRequest::calculateCustomPlanVariance($expectedFutureValue + 3.5, $expectedFutureValue))->toBeLessThan(5);
});
