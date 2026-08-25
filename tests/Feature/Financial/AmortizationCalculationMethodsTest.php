<?php

namespace Tests\Feature\Financial;

use App\Services\Financial\Amortization\AmortizationCalculationService;
use Tests\TestCase;

class AmortizationCalculationMethodsTest extends TestCase
{
    private AmortizationCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AmortizationCalculationService::class);
    }

    public function test_it_calculates_interest_using_bcmath(): void
    {
        $interest = $this->service->calculateInterest(
            '88663008.93',
            '1.00'
        );

        $this->assertSame('886630.09', $interest);
    }

    public function test_it_calculates_principal_from_installment_and_interest(): void
    {
        $principal = $this->service->calculatePrincipal(
            '2103651.62',
            '886630.09'
        );

        $this->assertSame('1217021.53', $principal);
    }

    public function test_it_calculates_remaining_balance(): void
    {
        $balance = $this->service->calculateRemainingBalance(
            '88663008.93',
            '1217021.53'
        );

        $this->assertSame('87445987.40', $balance);
    }
}