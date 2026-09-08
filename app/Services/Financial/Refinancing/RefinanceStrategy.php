<?php

namespace App\Services\Financial\Refinancing;

use App\Models\AmortizationInstallment;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection;

interface RefinanceStrategy
{
    public function apply(Contract $contract, array $params): void;

    /**
     * Cuotas que la estrategia va a borrar o modificar. Se resuelve ANTES de
     * aplicar el cambio para dejar la copia en la bitácora.
     *
     * No valida: si la operación es imposible devuelve una colección vacía y
     * es `apply()` quien lanza el error.
     *
     * @return Collection<int, AmortizationInstallment>
     */
    public function affectedInstallments(Contract $contract, array $params): Collection;
}
