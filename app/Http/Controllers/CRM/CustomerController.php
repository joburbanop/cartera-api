<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\DTOs\CreateCustomerDTO;
use App\Services\CRM\CustomerService;
use App\Traits\ApiResponse;
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

        return $this->successResponse($customer, 'Cliente registrado exitosamente en el CRM.', 201);
    }
}