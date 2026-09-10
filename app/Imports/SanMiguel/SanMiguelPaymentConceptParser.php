<?php

namespace App\Imports\SanMiguel;

final class SanMiguelPaymentConceptParser
{
    public function parse(string $raw): SanMiguelPaymentConcept
    {
        $concept = $this->extractConcept($raw);
        $normalized = $this->normalize($concept);

        $hasPlusAbono = (bool) preg_match('/\+\s*ABONO|ABONO\s+EXTRA|ABONO\s+A?\s*CAPITAL/u', $normalized);
        $hasInicialWord = (bool) preg_match('/INICIAL/u', $normalized);
        $numbers = $this->extractNumbers($normalized);

        if ($hasInicialWord && $numbers === []) {
            return new SanMiguelPaymentConcept(
                kind: 'inicial',
                numbers: [],
                hasPlusAbono: $hasPlusAbono,
                inicialFirst: true,
            );
        }

        if ($numbers === [] && preg_match('/\bABONO\b/u', $normalized)) {
            return new SanMiguelPaymentConcept(
                kind: 'abono',
                numbers: [],
                hasPlusAbono: true,
                inicialFirst: $hasInicialWord,
            );
        }

        $kind = match (true) {
            $numbers === [] => 'unparsed',
            count($numbers) > 1 && $hasPlusAbono => 'range_plus_abono',
            count($numbers) > 1 => 'range',
            $hasPlusAbono => 'single_plus_abono',
            default => 'single',
        };

        $inicialFirst = $hasInicialWord || (($numbers[0] ?? 0) === 1 && count($numbers) === 1);

        return new SanMiguelPaymentConcept(
            kind: $kind,
            numbers: $numbers,
            hasPlusAbono: $hasPlusAbono,
            inicialFirst: $inicialFirst,
        );
    }

    public function extractConcept(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/Concepto:\s*(.+?)(?:\s*\||$)/u', $raw, $match)) {
            return trim($match[1]);
        }

        return $raw;
    }

    private function normalize(string $concept): string
    {
        $upper = mb_strtoupper(trim($concept));
        $upper = strtr($upper, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        ]);
        $upper = preg_replace('/\bC+UOTA\b/u', 'CUOTA', $upper) ?? $upper;
        $upper = preg_replace('/\bC+OUTA\b/u', 'CUOTA', $upper) ?? $upper;
        $upper = preg_replace('/\s+/u', ' ', $upper) ?? $upper;

        return $upper;
    }

    /**
     * @return list<int>
     */
    private function extractNumbers(string $normalized): array
    {
        if (! preg_match('/CUOTA\s+(.+)$/u', $normalized, $match)) {
            return [];
        }

        $chunk = trim((string) $match[1]);
        $chunk = preg_replace('/\+.*$/u', '', $chunk) ?? $chunk;
        $chunk = preg_replace('/\bINICIAL\b.*$/u', '', $chunk) ?? $chunk;
        $chunk = trim($chunk);
        if ($chunk === '' || ! preg_match('/\d/u', $chunk)) {
            return [];
        }

        preg_match_all('/\d+/u', $chunk, $digits);
        $seq = array_values(array_filter(
            array_map('intval', $digits[0] ?? []),
            fn (int $n) => $n > 0,
        ));
        if ($seq === []) {
            return [];
        }

        $hasListSep = (bool) preg_match('/[,]|Y/u', $chunk);
        $hasHyphen = (bool) preg_match('/[-–]/u', $chunk);
        if ($hasHyphen && ! $hasListSep && count($seq) >= 2) {
            $min = min($seq);
            $max = max($seq);
            if ($max >= $min && ($max - $min) <= 36) {
                return range($min, $max);
            }
        }

        return array_values(array_unique($seq));
    }
}
