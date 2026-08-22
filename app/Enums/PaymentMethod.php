<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case BANK = 'bank';
    case BARTER = 'barter';
    case TRANSFER = 'transfer';
    case CARD = 'card';
}