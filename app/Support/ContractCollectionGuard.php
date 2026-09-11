<?php

namespace App\Support;

use App\Enums\ContractStatus;
use App\Models\Contract;
use Illuminate\Validation\ValidationException;

/**
 * Bloquea cobros nuevos sobre un contrato ya cerrado.
 * No usar en reversas: esas sí pueden operar sobre terminado/rescindido.
 */
final class ContractCollectionGuard
{
    public static function closedMessage(ContractStatus $status): string
    {
        return 'Este contrato está '.$status->value.' y no admite nuevos pagos.';
    }

    public static function assertAcceptsPayments(Contract $contract): void
    {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::tryFrom((string) $contract->status);

        if ($status === null || ! $status->isClosed()) {
            return;
        }

        throw ValidationException::withMessages([
            'contract' => self::closedMessage($status),
        ]);
    }
}
