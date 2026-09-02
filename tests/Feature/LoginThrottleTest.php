<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    User::factory()->create([
        'email' => 'throttle@cartera.test',
        'password' => Hash::make('password'),
    ])->syncRoles([RoleName::ADMINISTRADOR->value]);
});

it('el sexto intento fallido de login responde 429', function () {
    $payload = [
        'email' => 'throttle@cartera.test',
        'password' => 'wrong-password',
    ];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/login', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('message', 'Demasiados intentos, espera un momento');
});

it('permite un login válido después de esperar el rate limiter', function () {
    $payload = [
        'email' => 'throttle@cartera.test',
        'password' => 'wrong-password',
    ];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/login', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/login', $payload)->assertStatus(429);

    $this->travel(61)->seconds();

    $this->postJson('/api/login', [
        'email' => 'throttle@cartera.test',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');
});
