<?php

namespace App\Services\Imports;

use App\Enums\LotStatus;
use App\Models\AmortizationInstallment;
use App\Models\Contract;
use App\Models\ContractPaymentPromise;
use App\Models\Lot;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

class SanMiguelWipeService
{
    /**
     * @return array{contracts: int, lots_reset: int}
     */
    public function run(): array
    {
        return DB::transaction(function () {
            $contracts = $this->sanMiguelContracts();
            $lotIds = $contracts->pluck('lot_id')->filter()->unique()->values();
            $contractIds = $contracts->pluck('id');
            $txIds = Transaction::query()->whereIn('contract_id', $contractIds)->pluck('id');

            if ($txIds->isNotEmpty() && Schema::hasTable('receipts')) {
                Receipt::query()->whereIn('transaction_id', $txIds)->delete();
            }

            AmortizationInstallment::query()->whereIn('contract_id', $contractIds)->delete();
            ContractPaymentPromise::query()->whereIn('contract_id', $contractIds)->delete();
            DB::table('contract_customer')->whereIn('contract_id', $contractIds)->delete();
            Transaction::query()->whereIn('contract_id', $contractIds)->delete();

            if (Schema::hasTable('activity_log')) {
                Activity::query()
                    ->where('subject_type', Contract::class)
                    ->whereIn('subject_id', $contractIds)
                    ->delete();
            }

            foreach ($contracts as $contract) {
                $contract->forceDelete();
            }

            $lotsReset = 0;
            if ($lotIds->isNotEmpty()) {
                $lotsReset = Lot::query()
                    ->whereIn('id', $lotIds)
                    ->update(['status' => LotStatus::DISPONIBLE->value]);
            }

            return [
                'contracts' => $contracts->count(),
                'lots_reset' => $lotsReset,
            ];
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, Contract>
     */
    private function sanMiguelContracts()
    {
        return Contract::query()
            ->withTrashed()
            ->where(function ($query) {
                $query->where('contract_number', 'like', 'SM-LOTE-%')
                    ->orWhere('contract_number', 'like', '%PRUEBA%')
                    ->orWhereHas('lot.project', function ($project) {
                        $project->whereRaw('LOWER(name) LIKE ?', ['%san miguel%']);
                    });
            })
            ->get();
    }
}
