<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Observers\TransactionObserver;
use App\Services\Collection\CascadeCollectionService;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Financial\Transaction\ExtraordinaryPayment\ExtraordinaryPaymentService;
use App\Services\Financial\Transaction\InstallmentPaymentAllocator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DashboardMetricsService::class);

        $this->app->singleton(CascadeCollectionService::class, function ($app) {
            return new CascadeCollectionService(
                $app->make(ExtraordinaryPaymentService::class),
                $app->make(InstallmentPaymentAllocator::class),
            );
        });
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(5)
                ->by($email.'|'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Demasiados intentos, espera un momento',
                        'errors' => null,
                    ], 429)->withHeaders($headers);
                });
        });

        RateLimiter::for('writes', function (Request $request) {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        Transaction::observe(TransactionObserver::class);

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