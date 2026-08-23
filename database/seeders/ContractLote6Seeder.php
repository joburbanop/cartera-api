<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Lot;
use App\Models\Contract;
use App\Models\AmortizationPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ContractLote6Seeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Genera la estructura completa para el LOTE 6 de William Ernesto Rojas Caicedo.
     * Incluye Cliente, Proyecto, Lote, Contrato y su Amortización v1 inicial.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Registrar o buscar Cliente (Datos Reales LOTE 6)
            $customer = Customer::updateOrCreate(
                ['document_number' => '94526871'],
                [
                    'name' => 'WILLIAM ERNESTO ROJAS CAICEDO',
                    'phone' => '0000000000',
                    'document_type' => 'CC',
                ]
            );

            // 2. Registrar o buscar Proyecto (San Miguel)
            $project = Project::firstOrCreate(
                ['name' => 'Proyecto San Miguel'],
                [
                    'name' => 'Proyecto San Miguel',
                    'description' => 'Proyecto San Miguel',
                    'location' => 'San Miguel',
                    'status' => 'active',
                ]
            );

            // 3. Registrar o buscar Lote (LOTE 6)
            $lot = Lot::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'number' => '6',
                ],
                [
                    'number' => '6',
                    'area_m2' => 150.00,
                    'price_m2' => 701306.67,
                    'list_price' => 105196000.00,
                    'status' => 'vendido',
                    'type' => 'residential',
                ]
            );

            // 4. Crear el Contrato con las condiciones comerciales exactas del Excel
            $contract = Contract::updateOrCreate(
                ['contract_number' => 'CONTRATO-SM-LOTE6'],
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
                    'status' => 'activo',
                ]
            );

            // 5. Poblar el motor matemático de amortización inicial (Versión v1)
            $this->seedAmortizationPlan($contract);
        });
    }

    /**
     * Genera la tabla de amortización v1 basada en el Sistema Francés original del Excel.
     */
    private function seedAmortizationPlan(Contract $contract): void
    {
        // Limpiamos registros previos de esta misma versión para evitar duplicados en reprocesos
        AmortizationPlan::where('contract_id', $contract->id)
            ->where('version', 1)
            ->delete();

        $balance = 94676400.00; // Valor neto del crédito ($105.1M lote - $10.5M inicial)
        $installmentValue = 2106024.00; // Cuota fija acordada
        $rate = 0.01; // Tasa mensual del 1%
        $firstInstallmentDate = Carbon::parse($contract->first_installment_date);

        for ($i = 1; $i <= $contract->term_months; $i++) {
            $interest = round($balance * $rate, 2);
            
            if ($i === $contract->term_months) {
                // Ajuste de centavos en la última cuota para extinguir saldo
                $principal = $balance;
                $installmentValue = $principal + $interest;
                $balance = 0.00;
            } else {
                $principal = round($installmentValue - $interest, 2);
                $balance = round($balance - $principal, 2);
            }

            // Sumar un mes consecutivo por cada cuota ordinaria
            $dueDate = $firstInstallmentDate->copy()->addMonths($i - 1)->format('Y-m-d');

            AmortizationPlan::create([
                'contract_id' => $contract->id,
                'version' => 1,
                'installment_number' => $i,
                'due_date' => $dueDate,
                'installment_value' => $installmentValue,
                'interest_value' => $interest,
                'principal_value' => $principal,
                'remaining_balance' => $balance,
                'quota_debt' => $installmentValue,
                'status' => 'sin_pagar',
                'is_active' => true,
            ]);
        }
    }
}
