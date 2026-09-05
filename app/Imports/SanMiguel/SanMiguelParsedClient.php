<?php

namespace App\Imports\SanMiguel;

use App\Enums\DocumentType;

class SanMiguelParsedClient
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $documentNumber,
        public readonly DocumentType $documentType,
        public readonly bool $documentMissing,
    ) {}
}
