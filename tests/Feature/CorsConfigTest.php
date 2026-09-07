<?php

it('nunca permite el origen comodín', function () {
    expect(config('cors.allowed_origins'))
        ->not->toContain('*')
        ->toContain('http://localhost:4200')
        ->toContain('http://127.0.0.1:4200');
});

it('refleja el origen local y omite orígenes no listados', function () {
    $allowed = $this->withHeaders([
        'Origin' => 'http://localhost:4200',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/login');

    $allowed->assertSuccessful()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');

    $denied = $this->withHeaders([
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/login');

    expect($denied->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
