<?php

namespace App\Services\Financial\Transaction\ExtraordinaryPayment\Options;

use App\Models\AmortizationInstallment;
use App\Models\Contract;

class PaymentReductionService extends AbstractExtraordinaryPaymentService
{
    public function apply(Contract $contract, AmortizationInstallment $installment, string $surplusAmount): AmortizationInstallment
    {
        return $this->processBasePayment($contract, $installment, $surplusAmount);
    }
}
