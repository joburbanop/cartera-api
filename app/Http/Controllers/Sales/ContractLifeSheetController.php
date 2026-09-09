<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Financial\LifeSheet\ContractLifeSheetService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ContractLifeSheetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ContractLifeSheetService $lifeSheetService,
    ) {}

    public function show(Contract $contract): JsonResponse
    {
        return $this->successResponse(
            $this->lifeSheetService->build($contract),
            'Hoja de vida obtenida exitosamente.',
        );
    }

    public function downloadPdf(Contract $contract): Response
    {
        $sheet = $this->lifeSheetService->build($contract);
        $contract->loadMissing(['lot.project']);

        $pdf = Pdf::loadView('pdf.life-sheet', [
            'contract' => $contract,
            'lot' => $contract->lot,
            'project' => $contract->lot?->project,
            'header' => $sheet['header'],
            'rows' => $sheet['rows'],
            'summary' => $sheet['summary'],
        ])->setPaper('letter', 'landscape');

        $lot = $contract->lot?->number ?? $contract->id;

        return $pdf->download(sprintf('hoja-de-vida-lote-%s.pdf', $lot));
    }
}
