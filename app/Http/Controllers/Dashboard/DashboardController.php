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
}
