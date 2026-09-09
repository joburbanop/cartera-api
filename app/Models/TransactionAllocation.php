<?php

namespace App\Models;

use App\Enums\AllocationTarget;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una porción de un pago repartido. La suma de las porciones de una
 * transacción es igual al monto que vio el banco.
 */
class TransactionAllocation extends Model
{
    protected $fillable = [
        'transaction_id',
        'target',
        'amortization_installment_id',
        'amount',
        'principal',
        'interest',
    ];

    protected function casts(): array
    {
        return [
            'target' => AllocationTarget::class,
            'amount' => 'decimal:2',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(AmortizationInstallment::class, 'amortization_installment_id');
    }

    /**
     * Graba la cuota regular y, si el extra va a capital, una línea aparte.
     * La suma de ambas es `$amountApplied` (la parte regular del movimiento).
     */
    public static function recordInstallmentAndCapital(
        int $transactionId,
        ?int $installmentId,
        string $amountApplied,
        string $principalDelta,
        string $interestDelta,
        string $extraDelta,
    ): void {
        $extra = FinancialRules::leftoverExceedsAbsorbedSurplus($extraDelta)
            ? self::money($extraDelta)
            : '0.00';
        $quotaAmount = self::maxZero(bcsub(self::money($amountApplied), $extra, 2));
        $quotaPrincipal = self::maxZero(bcsub(self::money($principalDelta), $extra, 2));

        if (bccomp($quotaAmount, '0.00', 2) > 0) {
            self::query()->create([
                'transaction_id' => $transactionId,
                'target' => AllocationTarget::INSTALLMENT,
                'amortization_installment_id' => $installmentId,
                'amount' => $quotaAmount,
                'principal' => $quotaPrincipal,
                'interest' => self::money($interestDelta),
            ]);
        }

        if (bccomp($extra, '0.00', 2) > 0) {
            self::query()->create([
                'transaction_id' => $transactionId,
                'target' => AllocationTarget::CAPITAL,
                'amortization_installment_id' => null,
                'amount' => $extra,
                'principal' => $extra,
                'interest' => '0.00',
            ]);
        }
    }

    private static function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private static function maxZero(string $value): string
    {
        return bccomp($value, '0.00', 2) > 0 ? $value : '0.00';
    }
}
