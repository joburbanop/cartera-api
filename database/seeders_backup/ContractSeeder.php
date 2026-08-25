<?php

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContractSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrFail();
        $lots = Lot::query()->limit(3)->get();

        $customers = [
            [
                'document_type' => 'CC',
                'document_number' => '1002003001',
                'name' => 'Ana María Gómez',
                'phone' => '3001112233',
                'email' => 'ana.gomez@example.com',
                'address' => 'Cra 30 # 45-12',
                'city' => 'Medellín',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'document_type' => 'CC',
                'document_number' => '1002003002',
                'name' => 'Carlos Andrés López',
                'phone' => '3012223344',
                'email' => 'carlos.lopez@example.com',
                'address' => 'Calle 12 # 80-33',
                'city' => 'Envigado',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
            [
                'document_type' => 'CC',
                'document_number' => '1002003003',
                'name' => 'Sofía Ramírez',
                'phone' => '3023334455',
                'email' => 'sofia.ramirez@example.com',
                'address' => 'Avenida 80 # 15-20',
                'city' => 'Bello',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
        ];

        foreach ($customers as $customerData) {
            Customer::query()->firstOrCreate(
                ['document_number' => $customerData['document_number']],
                $customerData
            );
        }

        $customerRecords = Customer::query()->limit(3)->get();

        foreach ($lots as $index => $lot) {
            $customer = $customerRecords[$index] ?? $customerRecords->first();

            Contract::query()->firstOrCreate(
                ['contract_number' => 'CTR-' . ($index + 1) . '-' . $lot->id],
                [
                    'customer_id' => $customer->id,
                    'lot_id' => $lot->id,
                    'seller_name' => 'Lina Torres',
                    'sale_price' => $lot->list_price,
                    'down_payment_pactada' => $lot->list_price * 0.25,
                    'term_months' => 48,
                    'interest_rate' => 1.00,
                    'start_date' => now()->toDateString(),
                    'initial_payment_date' => now()->addDays(7)->toDateString(),
                    'first_installment_date' => now()->addMonths(2)->toDateString(),
                    'regular_payment_start_date' => now()->addMonths(2)->toDateString(),
                    'preventa_installments_count' => 2,
                    'status' => ContractStatus::PREVENTA_INACTIVA->value,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );
        }
    }
}
