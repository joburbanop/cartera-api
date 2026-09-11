<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankAccountRequest;
use App\DTOs\CreateBankAccountDTO;
use App\Services\Financial\BankAccountService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\UpdateBankAccountRequest;
use App\DTOs\UpdateBankAccountDTO;
use App\Models\BankAccount;

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
    public function update(
        UpdateBankAccountRequest $request,
        BankAccount $bankAccount
    ): JsonResponse {
        $dto = UpdateBankAccountDTO::fromRequest($request);

        $bankAccount = $this->bankAccountService->updateBankAccount(
            $bankAccount,
            $dto,
            $request->user()->id
        );

        return $this->successResponse(
            $bankAccount,
            'Cuenta bancaria actualizada exitosamente.'
        );
    }
    public function archive(
        BankAccount $bankAccount
    ): JsonResponse {
        $this->bankAccountService->archiveBankAccount(
            $bankAccount,
            auth()->id()
        );

        return $this->successResponse(
            null,
            'Cuenta bancaria archivada exitosamente.'
        );
    }

    public function restore(
        int $bankAccount
    ): JsonResponse {
        $bankAccountModel = BankAccount::withTrashed()->findOrFail($bankAccount);

        $bankAccount = $this->bankAccountService->restoreBankAccount(
            $bankAccountModel,
            auth()->id()
        );

        return $this->successResponse(
            $bankAccount,
            'Cuenta bancaria restaurada exitosamente.'
        );
    }

    public function archived(): JsonResponse
    {
        $bankAccounts = $this->bankAccountService->getArchivedBankAccounts();

        return $this->successResponse(
            $bankAccounts,
            'Lista de cuentas bancarias archivadas obtenida exitosamente.'
        );
    }
}