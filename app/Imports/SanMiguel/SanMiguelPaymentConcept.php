<?php

namespace App\Imports\SanMiguel;

/**
 * Resultado del parser de concepto HV. No decide lotes: solo el texto.
 */
final class SanMiguelPaymentConcept
{
    /**
     * @param  list<int>  $numbers
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $numbers,
        public readonly bool $hasPlusAbono,
        public readonly bool $inicialFirst,
        public readonly string $leftoverOption = 'reducir_plazo',
    ) {}

    public function isInicialOnly(): bool
    {
        return $this->kind === 'inicial';
    }
}
