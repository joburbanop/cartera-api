<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\DTOs\CreateCustomerDTO;
use App\DTOs\UpdateCustomerDTO;
use App\Services\CRM\CustomerService;
use App\Traits\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CustomerService $customerService
    ) {}

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $dto = CreateCustomerDTO::fromRequest($request);
        $userId = auth()->id() ?? 1;

        $customer = $this->customerService->createCustomer($dto, $userId);

        return $this->successResponse(
            new CustomerResource($customer),
            'Cliente registrado exitosamente en el CRM.',
            201
        );
    }

    public function update(
        UpdateCustomerRequest $request,
        int $customer
    ): JsonResponse {
        $model = Customer::findOrFail($customer);

        $dto = UpdateCustomerDTO::fromRequest($request);
        $userId = auth()->id() ?? 1;

        $customer = $this->customerService->updateCustomer(
            $model,
            $dto,
            $userId
        );

        return $this->successResponse(
            new CustomerResource($customer),
            'Cliente actualizado exitosamente.'
        );
    }

    public function index(): JsonResponse
    {
        $customers = $this->customerService->getAllCustomers();

        return $this->successResponse(
            CustomerResource::collection($customers->items()),
            'Lista de clientes obtenida exitosamente.'
        );
    }

    public function show(int $customer): JsonResponse
    {
        $model = $this->customerService->getCustomerDetail($customer);

        return $this->successResponse(
            new CustomerResource($model),
            'Detalle del cliente obtenido exitosamente.'
        );
    }

    public function archive(int $customer): JsonResponse
    {
        try {
            $this->customerService->archiveCustomer($customer);

            return $this->successResponse(
                null,
                'Cliente archivado correctamente.'
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    public function activate(int $customer): JsonResponse
    {
        try {
           $this->customerService->activateCustomer($customer);

            return $this->successResponse(
                null,
                'Cliente activado correctamente.'
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    public function archived(): JsonResponse
    {
        $customers = $this->customerService->getArchivedCustomers();

        return $this->successResponse(
            CustomerResource::collection($customers->items()),
            'Lista de clientes archivados obtenida exitosamente.'
        );
    }
}