<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Administrador',
                'email' => 'admin@admin.com',
                'password' => Hash::make('password'),
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
            ]
        );

        BankAccount::query()->firstOrCreate(
            ['account_number' => '1234567890'],
            [
                'bank_name' => 'Bancolombia',
                'account_type' => 'savings',
                'is_active' => true,
                'holder_name' => 'Constructora San Miguel',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]
        );

        $this->call([
            ProjectSeeder::class,
            LotSeeder::class,
            FinancialTestSeeder::class,
            Contract053EimySeeder::class,
        ]);
    }
}