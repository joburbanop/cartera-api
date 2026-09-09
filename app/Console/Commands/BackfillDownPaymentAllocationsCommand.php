<?php

namespace App\Console\Commands;

use App\Enums\AllocationTarget;
use App\Enums\TransactionType;
use App\Models\AmortizationInstallment;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Services\Collection\TransactionAllocationRecorder;
use Illuminate\Console\Command;

/**
 * Solo down_payment: cada uno va entero a la cuota inicial. Los regulares
 * históricos no se reconstruyen — no hay rastro cierto pago→cuota.
 */
class BackfillDownPaymentAllocationsCommand extends Command
{
    protected $signature = 'allocations:backfill-down-payments {--dry-run}';

    protected $description = 'Crea allocations de cuota inicial para down_payments históricos que aún no las tienen.';

    public function handle(TransactionAllocationRecorder $recorder): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Transaction::query()
            ->where('transaction_type', TransactionType::DOWN_PAYMENT)
            ->whereDoesntHave(
                'allocations',
                fn ($allocations) => $allocations->where('target', AllocationTarget::DOWN_PAYMENT->value)
            )
            ->orderBy('id');

        $count = (clone $query)->count();
        $this->info($dryRun
            ? "Se crearían {$count} allocations de inicial."
            : "Creando {$count} allocations de inicial.");

        $created = 0;
        $query->with('contract')->chunkById(100, function ($transactions) use ($recorder, $dryRun, &$created) {
            $initials = AmortizationInstallment::query()
                ->whereIn('contract_id', $transactions->pluck('contract_id')->unique()->all())
                ->where('installment_number', 0)
                ->get()
                ->keyBy('contract_id');

            foreach ($transactions as $transaction) {
                if ($dryRun) {
                    $created++;
                    continue;
                }

                $recorder->recordDownPayment(
                    $transaction,
                    $initials->get($transaction->contract_id),
                    (string) $transaction->amount,
                    (string) $transaction->amount,
                );
                $created++;
            }
        });

        $this->info($dryRun ? "Dry-run: {$created}." : "Creadas: {$created}.");

        return self::SUCCESS;
    }
}
