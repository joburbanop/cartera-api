<?php

use App\Imports\SanMiguel\SanMiguelHistoricalAlignments;

it('reconoce el libro oficial de San Miguel por el nombre de archivo', function () {
    expect(SanMiguelHistoricalAlignments::isOfficialWorkbook(
        '/tmp/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx'
    ))->toBeTrue()
        ->and(SanMiguelHistoricalAlignments::isOfficialWorkbook(
            sys_get_temp_dir().'/san-miguel-import-abc.xlsx'
        ))->toBeFalse();
});
