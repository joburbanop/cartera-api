<?php

namespace App\Services\Sales;

use App\DTOs\CreateContractDTO;
use App\Models\Contract;
use App\Models\Lot;
use Illuminate\Support\Facades\DB;

class ContractService
{
    public function createContract(CreateContractDTO $dto): Contract
    {
        return DB::transaction(function () use ($dto) {
            // 1. Creamos el contrato (Nace como 'preventa_inactiva' por defecto)
            $contract = Contract::create([
                'contract_number' => $dto->contractNumber,
                'customer_id' => $dto->customerId,
                'lot_id' => $dto->lotId,
                'seller_name' => $dto->sellerName,
                'sale_price' => $dto->salePrice,
                'down_payment_pactada' => $dto->downPaymentPactada,
                'term_months' => $dto->termMonths,
                'interest_rate' => $dto->interestRate,
                'start_date' => $dto->startDate,
                'created_by' => $dto->createdBy,
            ]);

            // 2. Regla de Negocio: Separar el lote para que no se venda doble
            $lot = Lot::findOrFail($dto->lotId);
            $lot->update(['status' => 'separado']); 

            return $contract;
        });
    }


    public function getAllContracts(int $perPage = 15)
    {
        // Traemos el contrato junto con los datos de su cliente y lote
        return Contract::with(['customer', 'lot'])->latest()->paginate($perPage);
    }
}