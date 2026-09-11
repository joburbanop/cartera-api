<?php

namespace App\Services\Financial;

use App\DTOs\CreateBankAccountDTO;
use App\Models\BankAccount;
use App\DTOs\UpdateBankAccountDTO;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    public function createBankAccount(CreateBankAccountDTO $dto): BankAccount
    {
       return BankAccount::create([
            'bank_name' => $dto->bankName,
            'account_number' => $dto->accountNumber,
            'account_type' => $dto->accountType,
            'holder_name' => $dto->holderName, // <--- Esta llave debe decir 'holder_name'
        ]);
    }

    // Agrega este método
    public function getAllBankAccounts(int $perPage = 15)
    {
        return BankAccount::latest()->paginate($perPage);
    }

    public function updateBankAccount(
        BankAccount $bankAccount,
        UpdateBankAccountDTO $dto,
        int $userId
    ): BankAccount {
        $bankAccount->update([
            'holder_name' => $dto->holderName,
            'is_active' => $dto->isActive,
            'updated_by' => $userId,
        ]);

        return $bankAccount->fresh();
    }
   

    public function archiveBankAccount(
        BankAccount $bankAccount,
        int $userId
    ): void {
        if ($bankAccount->projects()->exists()) {
            throw ValidationException::withMessages([
                'bank_account' => 'No se puede archivar una cuenta bancaria asociada a uno o más proyectos.',
            ]);
        }

        $bankAccount->update([
            'is_active' => false,
            'deleted_by' => $userId,
            'updated_by' => $userId,
        ]);

        $bankAccount->delete();
    }

    public function restoreBankAccount(
        BankAccount $bankAccount,
        int $userId
    ): BankAccount {
        $bankAccount->restore();

        $bankAccount->update([
            'is_active' => true,
            'deleted_by' => null,
            'updated_by' => $userId,
        ]);

        return $bankAccount->fresh();
    }
    public function getArchivedBankAccounts(int $perPage = 15)
    {
        return BankAccount::onlyTrashed()
            ->latest('deleted_at')
            ->paginate($perPage);
    }

}