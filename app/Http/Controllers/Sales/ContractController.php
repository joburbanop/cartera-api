<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractRequest;
use App\DTOs\CreateContractDTO;
use App\Services\Sales\ContractService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Models\Contract;

class ContractController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ContractService $contractService
    ) {}

    public function store(StoreContractRequest $request): JsonResponse
    {
        $dto = CreateContractDTO::fromRequest($request);
        
        $contract = $this->contractService->createContract($dto);

        return $this->successResponse($contract, 'Contrato registrado en Preventa y lote separado exitosamente.', 201);
    }


    public function index(): JsonResponse
    {
        $contracts = $this->contractService->getAllContracts();

        return $this->successResponse($contracts, 'Lista de contratos obtenida exitosamente.');
    }
    

   public function show(Contract $contract)
    {
        //cargamos los datos del lote, cliente , proyecto y estado del contrato
        $contract->load(['lot', 'customer', 'lot.project','status']); 

        return $this->successResponse(
            $contract,
            'Detalles del contrato obtenidos exitosamente.'
        );
    }
}