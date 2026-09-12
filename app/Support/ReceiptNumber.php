<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * Número de recibo físico tal como lo usa San Miguel: un token
 * (`0258` o `0258-0289`). Las comas del formulario se normalizan a guion.
 */
final class ReceiptNumber
{
    public const DUPLICATE = 'Este recibo ya existe en este contrato.';

    /**
     * Un contrato no reutiliza un recibo abierto.
     * No es índice único: el par preventa+cascada y la reversa copian el mismo número.
     */
    public static function assertUnusedOnContract(int $contractId, ?string $receiptNumber): void
    {
        $token = self::normalize($receiptNumber);
        if ($token === null) {
            return;
        }

        $exists = Transaction::query()
            ->where('contract_id', $contractId)
            ->where('receipt_number', $token)
            ->whereNull('reversed_at')
            ->where('transaction_type', '!=', TransactionType::PAYMENT_REVERSAL->value)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'receipt_number' => self::DUPLICATE,
            ]);
        }
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $token = trim($value);
        if ($token === '') {
            return null;
        }

        $token = str_replace([',', ';', '/', '\\'], '-', $token);
        $token = preg_replace('/\s*-\s*/', '-', $token) ?? $token;
        $token = preg_replace('/\s+/', '', $token) ?? $token;
        $token = preg_replace('/-+/', '-', $token) ?? $token;
        $token = trim($token, '-');

        if ($token === '') {
            return null;
        }

        return mb_substr($token, 0, 80);
    }

    public static function mergeIntoNotes(?string $notes, ?string $receiptNumber): ?string
    {
        $token = self::normalize($receiptNumber);
        if ($token === null) {
            return $notes !== null && trim($notes) !== '' ? $notes : null;
        }

        $prefix = 'Recibo #'.$token;
        $current = trim((string) $notes);
        if ($current === '') {
            return $prefix;
        }

        if (preg_match('/Recibo\s*#\s*[^|]+/u', $current)) {
            $replaced = preg_replace('/Recibo\s*#\s*[^|]+/u', $prefix, $current, 1) ?? $current;

            return trim(preg_replace('/\s*\|\s*/', ' | ', $replaced) ?? $replaced);
        }

        return $prefix.' | '.$current;
    }

    public static function fromStored(?string $column, ?string $notes): ?string
    {
        $fromColumn = trim((string) $column);
        if ($fromColumn !== '') {
            return $fromColumn;
        }

        if (preg_match('/Recibo\s*#\s*([^|]+)/u', (string) $notes, $match)) {
            $value = trim($match[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
