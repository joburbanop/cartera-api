<?php

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinancialTestSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            $user = User::query()->firstOrFail();

            /*
             * 1. CLIENTE
             */
            $customer = Customer::updateOrCreate(
                [
                    'document_number' => '94526871',
                ],
                [
                    'name' => 'WILLIAM ERNESTO ROJAS CAICEDO',
                    'phone' => '0000000000',
                    'document_type' => 'CC',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );

            /*
             * 2. PROYECTO
             */
            $project = Project::firstOrCreate(
                [
                    'name' => 'Proyecto San Miguel',
                ],
                [
                    'description' => 'Proyecto San Miguel',
                    'location' => 'San Miguel',
                    'status' => 'active',
                ]
            );

            /*
             * 3. LOTE 6
             */
            $lot = Lot::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'number' => '6',
                ],
                [
                    'area_m2' => 150.00,
                    'price_m2' => 701306.67,
                    'list_price' => 105196000.00,
                    'status' => 'vendido',
                    'type' => 'residential',
                ]
            );

            /*
             * 4. CONTRATO
             */
            $contract = Contract::updateOrCreate(
                [
                    'contract_number' => 'CONTRATO-SM-LOTE6',
                ],
                [
                    'customer_id' => $customer->id,
                    'lot_id' => $lot->id,
                    'seller_name' => 'Lina',
                    'sale_price' => 105196000.00,
                    'down_payment_pactada' => 10519600.00,
                    'term_months' => 60,
                    'interest_rate' => 1.00,
                    'start_date' => '2025-10-05',
                    'initial_payment_date' => '2025-10-05',
                    'first_installment_date' => '2025-11-05',
                    'regular_payment_start_date' => '2025-11-05',
                    'preventa_installments_count' => 0,
                    'status' => ContractStatus::ACTIVO->value,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );

            /*
             * 5. LIMPIAR AMORTIZACIÓN PREVIA
             */
            AmortizationInstallment::where(
                'contract_id',
                $contract->id
            )->delete();

            /*
             * 6. GENERAR AMORTIZACIÓN
             */
            $this->generateAmortization($contract);
        });
    }

    private function generateAmortization(Contract $contract): void
    {
        $balance = 94676400.00;
        $rate = 0.01;
        $installmentValue = 2106024.00;

        $firstInstallmentDate = Carbon::parse(
            $contract->first_installment_date
        );

        for ($i = 1; $i <= $contract->term_months; $i++) {

            $interest = round(
                $balance * $rate,
                2
            );

            if ($i === $contract->term_months) {

                $principal = round(
                    $balance,
                    2
                );

                $currentInstallmentValue = round(
                    $principal + $interest,
                    2
                );

                $newBalance = 0.00;

            } else {

                $currentInstallmentValue = $installmentValue;

                $principal = round(
                    $currentInstallmentValue - $interest,
                    2
                );

                $newBalance = round(
                    $balance - $principal,
                    2
                );
            }

            $dueDate = $firstInstallmentDate
                ->copy()
                ->addMonths($i - 1);

            AmortizationInstallment::create([
                'contract_id' => $contract->id,
                'installment_number' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'receipt_number' => null,
                'payment_date' => null,
                'installment_value' => $currentInstallmentValue,
                'extra_payment' => 0.00,
                'interest_value' => $interest,
                'principal_value' => $principal,
                'interest_paid' => 0.00,
                'principal_paid' => 0.00,
                'quota_debt' => $currentInstallmentValue,
                'remaining_balance' => $newBalance,
                'projected_balance' => $newBalance,
                'status' => 'pending',
            ]);

            $balance = $newBalance;
        }
    }
}