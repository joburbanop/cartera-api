<?php

use App\Imports\SanMiguel\SanMiguelLifeSheetParser;
use App\Services\Imports\SanMiguelLifeSheetLocator;

/**
 * Linux distingue app/imports de app/Imports; macOS no. El test construye las
 * dos carpetas como hermanas si el disco lo permite; si no (APFS), las crea
 * bajo raíces distintas y el locator las sonda con el mismo criterio de
 * mayúsculas que en producción.
 *
 * @return array{0: string, 1: string, 2: list<string>} workbookDir, hvDir, roots
 */
function sanMiguelCaseDistinctImportDirs(string $root): array
{
    $app = $root.'/app';
    mkdir($app, 0777, true);

    $lower = $app.'/imports';
    $upper = $app.'/Imports';
    mkdir($lower, 0777, true);

    $createdUpper = @mkdir($upper, 0777, true);
    $distinct = $createdUpper
        && realpath($lower) !== false
        && realpath($upper) !== false
        && realpath($lower) !== realpath($upper);

    if ($distinct) {
        return [$lower, $upper, [$root]];
    }

    $other = $root.'/other';
    $otherApp = $other.'/app';
    $otherUpper = $otherApp.'/Imports';
    mkdir($otherUpper, 0777, true);

    return [$lower, $otherUpper, [$root, $other]];
}

function removeSanMiguelCaseTree(string $root): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($root);
}

it('encuentra las HV en Imports aunque el libro de amortización esté en imports', function () {
    $root = sys_get_temp_dir().'/sm-case-'.uniqid();
    mkdir($root, 0777, true);
    [$workbookDir, $hvDir, $roots] = sanMiguelCaseDistinctImportDirs($root);

    mkdir($hvDir.'/SanMiguel', 0777, true);
    file_put_contents($hvDir.'/SanMiguel/SanMiguelWorkbookParser.php', '<?php');
    file_put_contents($workbookDir.'/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx', '');
    file_put_contents($hvDir.'/SAN MIGUEL HV 1-10.xlsx', '');
    file_put_contents($hvDir.'/SAN_MIGUEL_HV_11-20.xlsx', '');
    file_put_contents($hvDir.'/notas.xlsx', '');

    $locator = new SanMiguelLifeSheetLocator(
        new SanMiguelLifeSheetParser,
        $roots,
        $root.'/storage-imports-ausente',
    );

    $found = array_map('basename', $locator->discoverFiles(
        $workbookDir.'/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx',
    ));

    expect($found)->toBe(['SAN MIGUEL HV 1-10.xlsx', 'SAN_MIGUEL_HV_11-20.xlsx'])
        ->and($locator->dataDirectories($workbookDir.'/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx'))
        ->toContain(realpath($workbookDir))
        ->and($locator->dataDirectories($workbookDir.'/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx'))
        ->toContain(realpath($hvDir));

    removeSanMiguelCaseTree($root);
});

it('deduplica por basename si la misma HV aparece en imports y en Imports', function () {
    $root = sys_get_temp_dir().'/sm-case-dedup-'.uniqid();
    mkdir($root, 0777, true);
    [$workbookDir, $hvDir, $roots] = sanMiguelCaseDistinctImportDirs($root);

    file_put_contents($workbookDir.'/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx', '');
    file_put_contents($workbookDir.'/SAN MIGUEL HV 1-10.xlsx', 'from-imports');
    file_put_contents($hvDir.'/SAN MIGUEL HV 1-10.xlsx', 'from-Imports');

    $locator = new SanMiguelLifeSheetLocator(
        new SanMiguelLifeSheetParser,
        $roots,
        $root.'/storage-imports-ausente',
    );

    $found = $locator->discoverFiles($workbookDir.'/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx');

    expect($found)->toHaveCount(1)
        ->and(basename($found[0]))->toBe('SAN MIGUEL HV 1-10.xlsx');

    removeSanMiguelCaseTree($root);
});
