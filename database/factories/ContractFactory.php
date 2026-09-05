<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        $salePrice = (string) $this->faker->randomFloat(2, 800000, 20000000);
        $downPayment = (string) $this->faker->randomFloat(2, 100000, 3500000);

        $startDate = now()->subMonths(2)->toDateString();
        $firstInstallmentDate = now()->addMonth()->toDateString();

        return [
            'contract_number' => 'CTR-'.$this->faker->unique()->numerify('######'),
            'customer_id' => Customer::factory(),
            'lot_id' => Lot::factory(),
            'seller_name' => $this->faker->name(),
            'sale_price' => $salePrice,
            'down_payment_pactada' => $downPayment,
            'term_months' => $this->faker->numberBetween(12, 60),
            'interest_rate' => (string) $this->faker->randomFloat(2, 0.50, 2.50),
            'start_date' => $startDate,
            'initial_payment_date' => now()->toDateString(),
            'first_installment_date' => $firstInstallmentDate,
            'regular_payment_start_date' => $firstInstallmentDate,
            'preventa_installments_count' => 0,
            'status' => 'activo',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Contract $contract) {
            if ($contract->customer_id) {
                $contract->syncHolders((int) $contract->customer_id);
            }
        });
    }
}
