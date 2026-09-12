<?php

namespace App\Services\Financial\Withdrawal;

use App\Enums\TransactionType;
use App\Enums\WithdrawalCause;
use App\Models\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Enums\ContractStatus;
use App\Enums\LotStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;


class WithdrawalService
{
    /**
     * Calcula la liquidación de un desistimiento en preventa.
     *
     * Reglas:
     * - Los aportes corresponden únicamente a pagos DOWN_PAYMENT.
     * - Solo se consideran pagos realizados hasta la fecha de solicitud.
     * - La base de la multa es el sale_price del contrato.
     * - Retracto de ley: 0%.
     * - Fuerza mayor: porcentaje manual entre 0% y 100%.
     * - Voluntario: porcentaje entre 10% y 100%, por defecto 10%.
     */
    public function calculatePreventa(
        Contract $contract,
        WithdrawalCause $cause,
        Carbon $requestDate,
        ?float $retentionPercentage = null
    ): array {
        if ($contract->status->value !== 'preventa_inactiva') {
                    throw ValidationException::withMessages([
                'contract_id' => 'El contrato no se encuentra en estado de preventa inactiva.',
            ]);
        }

        $contributions = $this->calculateContributions(
            $contract,
            $requestDate
        );

        $retentionPercentage = $this->resolveRetentionPercentage(
            $cause,
            $retentionPercentage
        );

       $penaltyAmount = round(
            $contributions * ($retentionPercentage / 100),
            2
        );

        $refundBalance = round(
            $contributions - $penaltyAmount,
            2
        );

        if ($refundBalance < 0) {
            $refundBalance = 0;
        }

        $salePrice = (float) $contract->sale_price;

        return [
            'contract_id' => $contract->id,
            'type' => 'preventa',
            'request_date' => $requestDate->toDateString(),
            'cause' => $cause->value,

            'sale_price' => $salePrice,
            'contributions' => $contributions,

            'standard_retention_percentage' => $this->getStandardRetentionPercentage($cause),
            'authorized_retention_percentage' => $retentionPercentage,

            'penalty_amount' => $penaltyAmount,
            'refund_balance' => $refundBalance,
        ];
    }

    /**
 * Registra un desistimiento en preventa.
 *
 * El cálculo de la liquidación se realiza mediante calculatePreventa().
 * El registro y los cambios de estado se ejecutan dentro de una
 * transacción para mantener la consistencia de la operación.
 */
public function createPreventa(
    Contract $contract,
    WithdrawalCause $cause,
    Carbon $requestDate,
    ?float $retentionPercentage = null,
    ?string $observations = null,
    ?string $modificationJustification = null,
    ?int $createdBy = null
): Withdrawal {
    return DB::transaction(function () use (
        $contract,
        $cause,
        $requestDate,
        $retentionPercentage,
        $observations,
        $modificationJustification,
        $createdBy
    ) {
        $calculation = $this->calculatePreventa(
            $contract,
            $cause,
            $requestDate,
            $retentionPercentage
        );

        $status = $calculation['refund_balance'] > 0
            ? WithdrawalStatus::PENDING
            : WithdrawalStatus::COMPLETED;

        $withdrawal = Withdrawal::create([
            ...$calculation,

            'observations' => $observations,
            'modification_justification' => $modificationJustification,

            'status' => $status,

            'created_by' => $createdBy,
        ]);

        $contract->update([
            'status' => ContractStatus::RESCINDIDO,
        ]);

        $contract->lot->update([
            'status' => LotStatus::DISPONIBLE,
        ]);

        return $withdrawal;
    });
}

    /**
     * Calcula los aportes realizados hasta la fecha de solicitud.
     *
     * En preventa solamente se consideran pagos de cuota inicial.
     */
    private function calculateContributions(
        Contract $contract,
        Carbon $requestDate
    ): float {
        return round(
            (float) $contract->transactions()
                ->where('transaction_type', TransactionType::DOWN_PAYMENT->value)
                ->whereDate('transaction_date', '<=', $requestDate->toDateString())
                ->sum('amount'),
            2
        );
    }

    /**
     * Determina el porcentaje de retención aplicable.
     */
    private function resolveRetentionPercentage(
        WithdrawalCause $cause,
        ?float $retentionPercentage
    ): float {
        return match ($cause) {
            WithdrawalCause::RETRACTO_DE_LEY => 0,

            WithdrawalCause::FUERZA_MAYOR => $this->validatePercentage(
                $retentionPercentage,
                0,
                100
            ),

            WithdrawalCause::VOLUNTARIO => $this->validatePercentage(
                $retentionPercentage ?? 10,
                10,
                100
            ),
        };
    }

    /**
     * Porcentaje estándar/propuesto según la causa.
     */
    private function getStandardRetentionPercentage(
        WithdrawalCause $cause
    ): float {
        return match ($cause) {
            WithdrawalCause::RETRACTO_DE_LEY => 0,
            WithdrawalCause::FUERZA_MAYOR => 0,
            WithdrawalCause::VOLUNTARIO => 10,
        };
    }

    /**
     * Valida un porcentaje de retención.
     */
    private function validatePercentage(
        ?float $percentage,
        float $minimum,
        float $maximum
    ): float {
        if ($percentage === null) {
           throw ValidationException::withMessages([
                'retention_percentage' => 'Debe especificarse el porcentaje de retención.',
            ]);
        }

        if ($percentage < $minimum || $percentage > $maximum) {
                    throw ValidationException::withMessages([
                'retention_percentage' => "El porcentaje de retención debe estar entre {$minimum}% y {$maximum}%.",
            ]);
        }

        return round($percentage, 2);
    }
}