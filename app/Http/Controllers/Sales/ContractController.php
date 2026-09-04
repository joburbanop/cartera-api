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
        $customerId = $request->input('customer_id');

        if ($customerId && Customer::whereKey($customerId)->exists()) {
            return;
        }

        $customerName = $request->input('customer_name')
            ?? $request->input('cliente_nombre')
            ?? 'Cliente de Prueba';

        $customerDocument = $request->input('customer_document')
            ?? $request->input('document_number')
            ?? $request->input('document')
            ?? '99999999';

        $customerPhone = $request->input('customer_phone')
            ?? $request->input('phone')
            ?? '3000000000';

        $customerEmail = $request->input('customer_email')
            ?? $request->input('email')
            ?? 'cliente.prueba@example.com';

        $customer = Customer::firstOrCreate(
            ['document_number' => $customerDocument],
            [
                'document_type' => 'CC',
                'document_number' => $customerDocument,
                'name' => $customerName,
                'phone' => $customerPhone,
                'email' => $customerEmail,
                'address' => $request->input('customer_address') ?? $request->input('address') ?? null,
                'city' => $request->input('customer_city') ?? $request->input('city') ?? null,
                'created_by' => auth()->id() ?? 1,
            ]
        );

        $request->merge(['customer_id' => $customer->id]);
    }

    public function index(Request $request): JsonResponse
    {
        $lotId = $request->filled('lot_id') ? (int) $request->query('lot_id') : null;
        $perPage = min(100, max(1, (int) $request->integer('per_page', 15)));
        $contracts = $this->contractService->getAllContracts($perPage, $lotId);

        return $this->successResponse($contracts, 'Lista de contratos obtenida exitosamente.');
    }

    public function show(Contract $contract)
    {
        // Cargamos los datos del lote, cliente, proyecto, cuentas del proyecto y transacciones
        $contract->load([
            'lot',
            'customer',
            'customers',
            'lot.project.bankAccounts',
            'transactions',
        ]);

        return $this->successResponse(
            $contract,
            'Detalles del contrato obtenidos exitosamente.'
        );
    }
}
