<?php

namespace App\Services\Financial;

use App\DTOs\CreateBankAccountDTO;
use App\Models\BankAccount;

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
}