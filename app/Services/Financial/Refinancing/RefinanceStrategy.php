<?php

namespace App\Services\Financial\Refinancing;

use App\Models\Contract;

interface RefinanceStrategy
{
    public function apply(Contract $contract, array $params): void;
}
