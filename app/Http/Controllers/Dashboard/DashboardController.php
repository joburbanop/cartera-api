<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardMetricsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DashboardMetricsService $dashboardMetricsService
    ) {}

    public function carteraMora(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->carteraEnMora(),
            'Cartera en mora obtenida exitosamente.'
        );
    }

    public function recaudoReciente(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->recaudoReciente(),
            'Recaudo reciente obtenido exitosamente.'
        );
    }

    public function proximosVencimientos(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->proximosVencimientos(),
            'Próximos vencimientos obtenidos exitosamente.'
        );
    }

    public function actividadReciente(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->actividadReciente(),
            'Actividad reciente obtenida exitosamente.'
        );
    }

    public function clientesTotales(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->clientesTotales(),
            'Resumen de clientes obtenido exitosamente.'
        );
    }

    public function recaudoMensual(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->recaudoMensual(),
            'Recaudo mensual obtenido exitosamente.'
        );
    }

    public function carteraVencidaResumen(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->carteraVencidaResumen(),
            'Resumen de cartera vencida obtenido exitosamente.'
        );
    }

    public function contratosPorEstado(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->contratosPorEstado(),
            'Contratos por estado obtenidos exitosamente.'
        );
    }

    public function lotesPorEstado(): JsonResponse
    {
        return $this->successResponse(
            $this->dashboardMetricsService->lotesPorEstado(),
            'Lotes por estado obtenidos exitosamente.'
        );
    }
}
