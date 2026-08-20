<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankAccountRequest;
use App\DTOs\CreateBankAccountDTO;
use App\Services\Financial\BankAccountService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class BankAccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BankAccountService $bankAccountService
    ) {}

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $dto = CreateBankAccountDTO::fromRequest($request);
        
        $bankAccount = $this->bankAccountService->createBankAccount($dto);

        return $this->successResponse($bankAccount, 'Cuenta bancaria registrada exitosamente.', 201);
    }

    // Agrega este método
    public function index(): JsonResponse
    {
        $bankAccounts = $this->bankAccountService->getAllBankAccounts();

        return $this->successResponse($bankAccounts, 'Lista de cuentas bancarias obtenida exitosamente.');
    }
}