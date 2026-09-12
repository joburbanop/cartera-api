<?php

namespace App\Enums;

enum ResidualBalanceStatus: string
{
    case PENDIENTE = 'pendiente';
    case COBRADO = 'cobrado';
}
