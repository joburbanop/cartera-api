<?php

namespace Tests\Unit;

use App\Support\AmortizationScheduleDisplay;
use PHPUnit\Framework\TestCase;

class AmortizationScheduleDisplayTest extends TestCase
{
    public function test_pending_row_uses_theoretical_split(): void
    {
        $row = (object) [
            'installment_number' => 3,
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'interest_value' => '994490.96',
            'principal_value' => '1273535.13',
        ];

        $this->assertSame(994490.96, AmortizationScheduleDisplay::interestAmount($row));
        $this->assertSame(1273535.13, AmortizationScheduleDisplay::amortizationAmount($row));
    }

    public function test_paid_row_prefers_collected_amounts(): void
    {
        $row = (object) [
            'installment_number' => 1,
            'interest_paid' => '1400170.50',
            'principal_paid' => '99829.50',
            'interest_value' => '1714431.44',
            'principal_value' => '1714431.44',
        ];

        $this->assertSame(1400170.5, AmortizationScheduleDisplay::interestAmount($row));
        $this->assertSame(99829.5, AmortizationScheduleDisplay::amortizationAmount($row));
    }

    public function test_zero_interest_from_excel_stays_zero(): void
    {
        $row = (object) [
            'installment_number' => 1,
            'interest_paid' => '0.00',
            'principal_paid' => '20731800.00',
            'interest_value' => '0.00',
            'principal_value' => '20731800.00',
        ];

        $this->assertSame(0.0, AmortizationScheduleDisplay::interestAmount($row));
        $this->assertSame(20731800.0, AmortizationScheduleDisplay::amortizationAmount($row));
    }

    public function test_partial_down_payment_uses_pactada_minus_quota_debt(): void
    {
        $row = (object) [
            'installment_number' => 0,
            'interest_paid' => '0.00',
            'principal_paid' => '0.00',
            'interest_value' => '0.00',
            'principal_value' => '16077970.00',
            'quota_debt' => '5577970.00',
        ];

        $this->assertSame(0.0, AmortizationScheduleDisplay::interestAmount($row));
        $this->assertSame(10500000.0, AmortizationScheduleDisplay::amortizationAmount($row));
    }
}
