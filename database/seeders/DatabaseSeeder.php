<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $user = User::query()->firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Administrador',
                'email' => 'admin@admin.com',
                'password' => Hash::make('password'),
            ]
        );
        $user->syncRoles(['administrador']);

        $socio = User::query()->firstOrCreate(
            ['email' => 'socio@cartera.test'],
            [
                'name' => 'Socio Gerencia',
                'email' => 'socio@cartera.test',
                'password' => Hash::make('password'),
            ]
        );
        $socio->syncRoles(['socio_gerencia']);

        $adminSistema = User::query()->firstOrCreate(
            ['email' => 'sistema@cartera.test'],
            [
                'name' => 'Admin Sistema',
                'email' => 'sistema@cartera.test',
                'password' => Hash::make('password'),
            ]
        );
        $adminSistema->syncRoles(['admin_sistema']);

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