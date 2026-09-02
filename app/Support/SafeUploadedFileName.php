<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SafeUploadedFileName
{
    public static function forReceipt(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';

        return Str::uuid()->toString().'.'.$extension;
    }

    public static function forContentDisposition(?string $storedName): string
    {
        $base = basename((string) $storedName);
        $base = preg_replace('/[\r\n\t\0\x0B"]+/', '', $base) ?? '';
        $base = preg_replace('/[^\p{L}\p{N}\.\-_]+/u', '_', $base) ?? '';

        return $base !== '' ? $base : 'receipt.bin';
    }
}
