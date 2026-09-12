<?php

namespace App\Services\Imports;

use App\Imports\SanMiguel\SanMiguelLifeSheetParser;

/**
 * Encuentra los Excel de hoja de vida junto al libro de amortización.
 *
 * En macOS `app/imports` y `app/Imports` son la misma carpeta. En Linux no:
 * las clases PHP viven en `app/Imports` y los Excel en `app/imports`. Si alguien
 * deja las HV en la carpeta de las clases, un `dirname($path)` ciego las pierde.
 */
class SanMiguelLifeSheetLocator
{
    /**
     * @param  list<string>  $applicationRoots  vacía = [base_path()]; los tests
     *                                          pasan raíces temporales
     */
    public function __construct(
        private readonly SanMiguelLifeSheetParser $parser,
        private readonly array $applicationRoots = [],
        private readonly ?string $storageImportsDirectory = null,
    ) {}

    /**
     * @return list<string> rutas absolutas, una por basename
     */
    public function discoverFiles(string $workbookPath): array
    {
        $found = [];

        foreach ($this->dataDirectories($workbookPath) as $directory) {
            foreach ($this->parser->discover($directory) as $file) {
                $found[basename($file)] ??= $file;
            }
        }

        $files = array_values($found);
        sort($files);

        return $files;
    }

    /**
     * @return list<string> directorios existentes, sin duplicar el mismo inode
     */
    public function dataDirectories(string $workbookPath): array
    {
        $candidates = [];

        $absoluteWorkbook = realpath($workbookPath);
        if ($absoluteWorkbook !== false) {
            $workbookDir = dirname($absoluteWorkbook);
            $candidates[] = $workbookDir;
            $candidates = [...$candidates, ...$this->importDirectoryVariants(dirname($workbookDir))];
        } elseif (is_file($workbookPath) || is_dir(dirname($workbookPath))) {
            $candidates[] = dirname($workbookPath);
        }

        if ($this->shouldProbeKnownAppLocations($absoluteWorkbook ?: $workbookPath)) {
            foreach ($this->roots() as $root) {
                $candidates[] = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'imports';
                $candidates[] = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Imports';
            }

            $storage = $this->storageImportsDirectory ?? storage_path('app/imports');
            $candidates[] = $storage;
        }

        return $this->uniqueExistingDirectories($candidates);
    }

    /**
     * Hijos del padre cuyo nombre es "imports" sin importar mayúsculas.
     *
     * @return list<string>
     */
    public function importDirectoryVariants(string $parent): array
    {
        if (! is_dir($parent)) {
            return [];
        }

        $matches = [];
        foreach (scandir($parent) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! preg_match('/^imports$/i', $entry)) {
                continue;
            }

            $path = rtrim($parent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $matches[] = $path;
            }
        }

        return $matches;
    }

    /**
     * Las rutas canónicas de la app solo se miran si el libro vive en este
     * proyecto (o el test inyectó raíces). Un fixture en /tmp no debe
     * arrastrar las HV reales de app/Imports.
     */
    private function shouldProbeKnownAppLocations(string $workbookPath): bool
    {
        if ($this->applicationRoots !== []) {
            return true;
        }

        $absolute = realpath($workbookPath) ?: $workbookPath;
        foreach ([base_path(), storage_path()] as $anchor) {
            $resolvedAnchor = realpath($anchor) ?: $anchor;
            if ($this->isUnder($absolute, $resolvedAnchor)) {
                return true;
            }
        }

        return false;
    }

    private function isUnder(string $path, string $anchor): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $anchor = rtrim(str_replace('\\', '/', $anchor), '/');

        return $path === $anchor || str_starts_with($path, $anchor.'/');
    }

    /**
     * @return list<string>
     */
    private function roots(): array
    {
        return $this->applicationRoots !== [] ? $this->applicationRoots : [base_path()];
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function uniqueExistingDirectories(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $key = realpath($directory) ?: $directory;
            $unique[$key] = $key;
        }

        return array_values($unique);
    }
}
