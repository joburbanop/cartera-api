<?php

namespace App\Enums;

enum ContractCustomerRole: string
{
    case TITULAR_PRINCIPAL = 'titular_principal';
    case CO_TITULAR = 'co_titular';

    public function label(): string
    {
        return 'Titular';
    }
}
