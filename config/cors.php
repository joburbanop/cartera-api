<?php

$defaultOrigins = 'http://localhost:4200,http://127.0.0.1:4200';

$origins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', $defaultOrigins))),
    static fn (string $origin): bool => $origin !== '' && $origin !== '*'
));

if ($origins === []) {
    $origins = [
        'http://localhost:4200',
        'http://127.0.0.1:4200',
    ];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Orígenes explícitos del front. Nunca uses "*". En producción define
    | CORS_ALLOWED_ORIGINS (lista separada por comas) en el .env.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
