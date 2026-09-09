<?php

declare(strict_types=1);

namespace App\Support;

final class ContractTabOrder
{
    public const DEFAULT = [
        'amortizacion',
        'hoja-vida',
        'promesa',
        'bitacora-contrato',
        'bitacora-cliente',
    ];

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    public static function normalize(mixed $ids): array
    {
        $known = array_flip(self::DEFAULT);
        $saved = [];

        if (is_array($ids)) {
            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($known[$id]) || in_array($id, $saved, true)) {
                    continue;
                }

                $saved[] = $id;
            }
        }

        foreach (self::DEFAULT as $id) {
            if (! in_array($id, $saved, true)) {
                $saved[] = $id;
            }
        }

        return $saved;
    }

    /**
     * @param  list<string>  $saved
     * @param  list<string>  $available
     * @return list<string>
     */
    public static function visible(array $saved, array $available): array
    {
        $saved = self::normalize($saved);
        $availableSet = array_flip($available);
        $ordered = [];

        foreach ($saved as $id) {
            if (isset($availableSet[$id])) {
                $ordered[] = $id;
            }
        }

        foreach ($available as $id) {
            if (! in_array($id, $ordered, true) && in_array($id, self::DEFAULT, true)) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    /**
     * Reorders the currently visible ids in place and leaves hidden ids where they were.
     *
     * @param  list<string>  $full
     * @param  list<string>  $newVisible
     * @return list<string>
     */
    public static function applyVisibleReorder(array $full, array $newVisible): array
    {
        $full = self::normalize($full);
        $allowed = array_flip($full);
        $visible = [];

        foreach ($newVisible as $id) {
            if (isset($allowed[$id]) && ! in_array($id, $visible, true)) {
                $visible[] = $id;
            }
        }

        $visibleSet = array_flip($visible);
        $index = 0;
        $result = [];

        foreach ($full as $id) {
            if (isset($visibleSet[$id])) {
                $result[] = $visible[$index++] ?? $id;
            } else {
                $result[] = $id;
            }
        }

        return self::normalize($result);
    }
}
