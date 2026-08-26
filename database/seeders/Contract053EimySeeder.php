<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Contract053EimySeeder extends Seeder
{
    public function run(): void
    {
        $contractId = 1;

        $promises = [
            [
                'contract_id' => $contractId,
                'payment_number' => 1,
                'expected_date' => '2025-08-05',
                'expected_amount' => 1000000.00,
                'description' => 'Cuota inicial',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'payment_number' => 2,
                'expected_date' => '2025-10-10',
                'expected_amount' => 9556000.00,
                'description' => 'Cuota inicial adicional',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'payment_number' => 3,
                'expected_date' => '2025-11-05',
                'expected_amount' => 1500000.00,
                'description' => 'Cuota ordinaria 1',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'payment_number' => 4,
                'expected_date' => '2025-12-05',
                'expected_amount' => 1500000.00,
                'description' => 'Cuota ordinaria 2',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'payment_number' => 5,
                'expected_date' => '2026-01-05',
                'expected_amount' => 3500000.00,
                'description' => 'Cuota prima',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'payment_number' => 18,
                'expected_date' => '2027-04-05',
                'expected_amount' => 11529120.00,
                'description' => 'Cuota balón aleatoria',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'payment_number' => 19,
                'expected_date' => '2027-05-05',
                'expected_amount' => 2501820.00,
                'description' => 'Cambio de cuota base',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('contract_payment_promises')->insert($promises);
    }
}
