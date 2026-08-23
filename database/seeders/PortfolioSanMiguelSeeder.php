<?php

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class PortfolioSanMiguelSeeder extends Seeder
{
    /**
     * Seed the database with the related project, lots, customers and contracts
     * for the San Miguel portfolio described in the sales sheet.
     */
    public function run(): void
    {
        $user = User::query()->firstOrFail();

        $project = Project::query()->firstOrCreate(
            ['name' => 'Proyecto San Miguel'],
            [
                'description' => 'Portafolio de lotes del proyecto San Miguel',
                'location' => 'San Miguel',
                'status' => 'active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]
        );

        $portfolio = [
            [
                'document_number' => '94526871',
                'name' => 'WILLIAM ERNESTO ROJAS CAICEDO',
                'phone' => '3001112233',
                'sale_price' => 105196000.00,
                'down_payment_pactada' => 10519600.00,
                'term_months' => 60,
                'interest_rate' => 1.00,
                'start_date' => '2025-10-05',
                'initial_payment_date' => '2025-10-05',
                'first_installment_date' => '2025-11-05',
                'regular_payment_start_date' => '2025-11-05',
                'contract_number' => 'SM-LOT-6',
                'lot_number' => '6',
            ],
            [
                'document_number' => '31896445',
                'name' => 'AURORA RODRIGUEZ VANEGAS',
                'phone' => '3012223344',
                'sale_price' => 105196000.00,
                'down_payment_pactada' => 27000000.00,
                'term_months' => 60,
                'interest_rate' => 0.00,
                'start_date' => '2025-10-05',
                'initial_payment_date' => '2025-10-05',
                'first_installment_date' => '2025-11-05',
                'regular_payment_start_date' => '2025-11-05',
                'contract_number' => 'SM-LOT-7',
                'lot_number' => '7',
            ],
            [
                'document_number' => '1144146148',
                'name' => 'DIANA CAROLINA RIVERA MOJICA',
                'phone' => '3023334455',
                'sale_price' => 97116000.00,
                'down_payment_pactada' => 9711600.00,
                'term_months' => 60,
                'interest_rate' => 1.00,
                'start_date' => '2025-10-05',
                'initial_payment_date' => '2025-10-05',
                'first_installment_date' => '2025-11-05',
                'regular_payment_start_date' => '2025-11-05',
                'contract_number' => 'SM-LOT-8',
                'lot_number' => '8',
            ],
            [
                'document_number' => '6341384',
                'name' => 'ARBEY ERAZO TORO',
                'phone' => '3034445566',
                'sale_price' => 97116000.00,
                'down_payment_pactada' => 9711600.00,
                'term_months' => 24,
                'interest_rate' => 1.00,
                'start_date' => '2025-10-05',
                'initial_payment_date' => '2025-10-05',
                'first_installment_date' => '2025-11-05',
                'regular_payment_start_date' => '2025-11-05',
                'contract_number' => 'SM-LOT-9',
                'lot_number' => '9',
            ],
        ];

        foreach ($portfolio as $entry) {
            $customer = Customer::query()->firstOrCreate(
                ['document_number' => $entry['document_number']],
                [
                    'document_type' => 'CC',
                    'name' => $entry['name'],
                    'phone' => $entry['phone'],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );

            $lot = Lot::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'number' => $entry['lot_number'],
                ],
                [
                    'area_m2' => 150.00,
                    'price_m2' => round($entry['sale_price'] / 150, 2),
                    'list_price' => $entry['sale_price'],
                    'status' => 'vendido',
                    'type' => 'residential',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );

            Contract::query()->firstOrCreate(
                ['contract_number' => $entry['contract_number']],
                [
                    'customer_id' => $customer->id,
                    'lot_id' => $lot->id,
                    'seller_name' => 'Lina',
                    'sale_price' => $entry['sale_price'],
                    'down_payment_pactada' => $entry['down_payment_pactada'],
                    'term_months' => $entry['term_months'],
                    'interest_rate' => $entry['interest_rate'],
                    'start_date' => $entry['start_date'],
                    'initial_payment_date' => $entry['initial_payment_date'],
                    'first_installment_date' => $entry['first_installment_date'],
                    'regular_payment_start_date' => $entry['regular_payment_start_date'],
                    'preventa_installments_count' => 0,
                    'status' => ContractStatus::ACTIVO->value,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );
        }
    }
}
