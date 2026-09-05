<?php

namespace App\Imports\SanMiguel;

final class SanMiguelHistoricalAlignments
{
    public const OFFICIAL_FILENAME = 'SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx';

    /**
     * Recibos que no aparecen en la tabla izquierda; se aplican como extra en la cuota #1.
     *
     * @var array<string, string>
     */
    public const ORPHAN_EXTRAS_ON_FIRST_INSTALLMENT = [
        '17' => '751000.00',
        '18' => '1451000.00',
        '34' => '250000.00',
        '37' => '7106.00',
        '38' => '7106.00',
        '42' => '250000.00',
    ];

    /**
     * Cascada "mismo día del mes" desde la cuota #1, conservando su fecha actual.
     *
     * @var list<string>
     */
    public const SAME_DAY_CASCADE_FROM_FIRST = ['18', '19', '23', '26', '39', '40', '47'];

    /**
     * Fija la cuota #1 y reescribe la cola en el mismo día del mes.
     *
     * @var array<string, string>
     */
    public const SAME_DAY_CASCADE_SET_FIRST = [
        '48' => '2025-10-20',
        '27' => '2025-03-30',
    ];

    /**
     * Cascada "fin de mes" desde la cuota #1.
     *
     * @var list<string>
     */
    public const MONTH_END_CASCADE_FROM_FIRST = ['4', '14', '22'];

    /**
     * Lote 21: la cuota #1 no se mueve; la cola arranca en la #2.
     */
    public const LOT_21 = '21';

    public const LOT_21_SECOND_INSTALLMENT_DUE = '2025-03-30';

    public static function isOfficialWorkbook(string $path): bool
    {
        return strcasecmp(basename($path), self::OFFICIAL_FILENAME) === 0;
    }
}
