<?php

namespace App\Providers;

use App\Services\Collection\CascadeCollectionService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CascadeCollectionService::class, function ($app) {
            return new CascadeCollectionService(
                $app->make(ExtraordinaryPaymentService::class),
                $app->make(InstallmentPaymentAllocator::class),
            );
        });
    }

    public function boot(): void
    {
        // Interceptamos la validación del Token
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, $isValid) {
            if (!$isValid) {
                return false;
            }

            // Calculamos el tiempo desde la última petición que hizo el usuario
            $lastActivity = $token->last_used_at ?? $token->created_at;
            $inactivityLimit = env('SANCTUM_TOKEN_INACTIVITY', 5);

            // Si el tiempo sin actividad supera nuestros 5 minutos...
            if (now()->diffInMinutes($lastActivity) >= $inactivityLimit) {
                $token->delete(); // Borramos la llave de la base de datos
                return false;     // Le cerramos la puerta (Error 401)
            }

            return true; // Si ha estado activa, la dejamos pasar
        });
    }
}