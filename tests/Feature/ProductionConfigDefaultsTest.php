<?php

it('usa America/Bogota como zona horaria por defecto', function () {
    expect(config('app.timezone'))->toBe('America/Bogota');
});

it('expone SANCTUM_TOKEN_INACTIVITY vía config y no env() suelto', function () {
    expect(config('sanctum.token_inactivity_minutes'))->toBe(5);

    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
    expect($provider)->toContain("config('sanctum.token_inactivity_minutes'")
        ->and($provider)->not->toContain("env('SANCTUM_TOKEN_INACTIVITY'");
});

it('trata la petición como HTTPS cuando nginx envía X-Forwarded-Proto', function () {
    $this->call('GET', '/up', server: [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        'REMOTE_ADDR' => '127.0.0.1',
    ])->assertOk();

    expect(request()->secure())->toBeTrue();
});
