<?php

namespace App\Support;

use App\Models\Contract;

/**
 * Lock pesimista del contrato para operaciones que mutan su estado financiero.
 *
 * Debe llamarse como primera lectura del contrato dentro de DB::transaction().
 * El lock se libera al commit o rollback de esa transacción.
 */
final class ContractFinancialLock
{
    public static function acquire(int $contractId): Contract
    {
        return Contract::query()->lockForUpdate()->findOrFail($contractId);
    }
}
