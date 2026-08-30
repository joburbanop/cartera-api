<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Search\SearchService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SearchService $searchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $results = $this->searchService->search(
            $request->user(),
            (string) $request->query('q', ''),
        );

        return $this->successResponse($results, 'Búsqueda completada.');
    }
}
