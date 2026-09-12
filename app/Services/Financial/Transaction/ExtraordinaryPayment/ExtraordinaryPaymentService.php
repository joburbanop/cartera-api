<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment;

use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Services\Financial\Transaction\ExtraordinaryPayment\Options\PaymentReductionService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\Options\TermReductionService;

class ExtraordinaryPaymentService
{
    public function __construct(
        private readonly TermReductionService $termReductionService,
        private readonly PaymentReductionService $paymentReductionService,
    ) {}

    public function handle(Contract $contract, AmortizationInstallment $installment, string $surplusAmount, string $option): AmortizationInstallment
    {
        $strategy = $this->resolveStrategy(strtolower($option));

        return $strategy->apply($contract, $installment, $surplusAmount);
    }

    private function resolveStrategy(string $option): object
    {
        return match ($option) {
            'abono_capital' => $this->termReductionService,
            'reducir_plazo' => $this->termReductionService,
            'reducir_cuota' => $this->paymentReductionService,
            default => $this->termReductionService,
        };
    }
}
