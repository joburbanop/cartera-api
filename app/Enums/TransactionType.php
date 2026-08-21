<?php

namespace App\Enums;

enum TransactionType: string
{
    case DOWN_PAYMENT = 'down_payment';
    case REGULAR_PAYMENT = 'regular_payment';
    case EXTRAORDINARY_PAYMENT = 'extraordinary_payment';
    case REFUND = 'refund';
}