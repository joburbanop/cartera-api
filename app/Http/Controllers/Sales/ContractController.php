<?php

namespace App\Http\Controllers\Sales;

use App\DTOs\CreateContractDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Services\Sales\ContractService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\DTOs\UpdateContractDTO;
use App\Http\Requests\UpdateContractRequest;

class ContractController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ContractService $contractService
    ) {}

    public function store(StoreContractRequest $request): JsonResponse
    {
        $this->ensureCustomerExists($request);

        $dto = CreateContractDTO::fromRequest($request);

        $contract = $this->contractService->createContract($dto);

        return $this->successResponse($contract, 'Contrato registrado en Preventa y lote separado exitosamente.', 201);
    }

    protected function ensureCustomerExists(StoreContractRequest $request): void
    {
        $customerId = $request->validated('customer_id');

        if ($customerId && Customer::whereKey($customerId)->exists()) {
            return;
        }

        $document = (string) $request->validated('customer_document');
        $customer = Customer::firstOrCreate(
            ['document_number' => $document],
            [
                'document_type' => 'CC',
                'document_number' => $document,
                'name' => $request->validated('customer_name'),
                'phone' => $request->validated('customer_phone'),
                'email' => $request->validated('customer_email'),
                'address' => $request->input('customer_address') ?? $request->input('address'),
                'city' => $request->input('customer_city') ?? $request->input('city'),
                'created_by' => $this->authenticatedUserId(),
            ]
        );

        $request->merge(['customer_id' => $customer->id]);
    }

    public function index(Request $request): JsonResponse
    {
        $lotId = $request->filled('lot_id') ? (int) $request->query('lot_id') : null;
        $perPage = min(100, max(1, (int) $request->integer('per_page', 15)));
        $contracts = $this->contractService->getAllContracts($perPage, $lotId, [
            'contract_number' => $request->query('contract_number'),
            'customer' => $request->query('customer'),
            'project_id' => $request->query('project_id'),
            'lot_number' => $request->query('lot_number'),
            'status' => $request->query('status'),
            'cartera' => $request->query('cartera'),
            'start_date_from' => $request->query('start_date_from'),
            'start_date_to' => $request->query('start_date_to'),
        ]);

        return $this->successResponse($contracts, 'Lista de contratos obtenida exitosamente.');
    }

    public function archived(Request $request): JsonResponse
        {
            $perPage = min(100, max(1, (int) $request->integer('per_page', 15)));

            $contracts = Contract::onlyTrashed()
                ->with([
                    'customer',
                    'customers',
                    'lot',
                    'lot.project',
                ])
                ->latest('deleted_at')
                ->paginate($perPage);

            return $this->successResponse(
                $contracts,
                'Lista de contratos archivados obtenida exitosamente.'
            );
        }

    public function show(Contract $contract)
    {
        // Cargamos los datos del lote, cliente, proyecto, cuentas del proyecto y transacciones
        $contract->load([
            'lot',
            'customer',
            'customers',
            'lot.project.bankAccounts',
            'transactions.allocations',
        ]);

        return $this->successResponse(
            $contract,
            'Detalles del contrato obtenidos exitosamente.'
        );
    }

    public function update(
        UpdateContractRequest $request,
        Contract $contract
    ): JsonResponse {
        $dto = UpdateContractDTO::fromRequest($request);

        $contract = $this->contractService->updateContract(
            $contract,
            $dto
        );

        return $this->successResponse(
            $contract,
            'Contrato actualizado correctamente.'
        );
    }
    public function archive(Contract $contract): JsonResponse
    {
        $contract = $this->contractService->archiveContract($contract);

        return $this->successResponse(
            $contract,
            'Contrato archivado correctamente.'
        );
    }

   public function restore(int $contract): JsonResponse
{
    $contract = Contract::withTrashed()->findOrFail($contract);

    $contract = $this->contractService->restoreContract($contract);

    return $this->successResponse(
        $contract,
        'Contrato restaurado correctamente.'
    );
}

}
