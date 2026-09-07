<?php

namespace App\Imports\SanMiguel;

use Carbon\Carbon;

final class SanMiguelCustomSchedules
{
    public const CELEBRATION_DATE = '2025-08-01';

    public const FIRST_INSTALLMENT_DATE = '2025-11-05';

    /**
     * @var array<string, string>
     */
    public const SALE_PRICES = [
        '6' => '130192851.00',
        '45' => '130643360.00',
    ];

    public static function isCustomLot(string $lotNumber): bool
    {
        return isset(self::SALE_PRICES[$lotNumber]);
    }

    public static function salePrice(string $lotNumber): string
    {
        return self::SALE_PRICES[$lotNumber];
    }

    /**
     * @return list<array{payment_number: int, expected_date: string, expected_amount: float, description: string}>
     */
    public static function promises(string $lotNumber): array
    {
        $balloon = $lotNumber === '6' ? 11391084.00 : 11529120.00;
        $later = $lotNumber === '6' ? 2493193.00 : 2501820.00;
        $start = Carbon::parse(self::FIRST_INSTALLMENT_DATE)->startOfDay();
        $rows = [];

        for ($number = 1; $number <= 48; $number++) {
            $amount = match (true) {
                in_array($number, [1, 2, 4, 5, 6, 7, 8, 10, 11, 12, 13, 14], true) => 1500000.00,
                in_array($number, [3, 9, 15], true) => 3500000.00,
                $number === 18 => $balloon,
                default => $later,
            };

            $rows[] = [
                'payment_number' => $number,
                'expected_date' => $start->copy()->addMonthsNoOverflow($number - 1)->toDateString(),
                'expected_amount' => $amount,
                'description' => 'Pago '.$number,
            ];
        }

        return $rows;
    }
}
