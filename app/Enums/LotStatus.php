<?php

namespace App\Enums;

enum LotStatus: string
{
    case DISPONIBLE = 'disponible';
    case PREVENTA = 'preventa';
    case VENDIDO = 'vendido';
    case ABOGADO = 'abogado'; // En cobro jurídico/pleito
}