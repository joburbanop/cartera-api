<?php

namespace App\Enums;

enum ContractStatus: string
{
    case PREVENTA_INACTIVA = 'preventa_inactiva';
    case ACTIVO = 'activo';
    case TERMINADO = 'terminado';
    case RESCINDIDO = 'rescindido';
}