<?php

namespace App\Http\Resources;

use App\Enums\ContractStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeContracts = $this->whenLoaded('activeContracts', fn () => $this->activeContracts, collect());
        $cantidadContratos = (int) ($this->active_contracts_count ?? $activeContracts->count());

        $loteName = 'Sin contrato';
        $estadoCartera = 'sin_contrato';

        if ($cantidadContratos > 0) {
            $lotesArray = $activeContracts
                ->map(function ($contract) {
                    if ($contract && $contract->lot) {
                        return $contract->lot->name;
                    }

                    return $contract && $contract->lot_id ? 'Lote '.$contract->lot_id : 'Lote sin nombre';
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            $loteName = ! empty($lotesArray) ? implode(', ', $lotesArray) : 'Lote sin nombre';

            $hasOverdue = $activeContracts->contains(function ($contract) {
                return $contract && $contract->installments->isNotEmpty();
            });

            $estadoCartera = $hasOverdue ? 'vencida' : 'al_dia';
        }

        return [
            'id' => $this->id,

            'document_type' => $this->document_type?->value ?? 'CC',
            'document_number' => $this->document_number,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,

            'lote' => $loteName,
            'cantidad_contratos' => $cantidadContratos,
            'estadoCartera' => $estadoCartera,

            'contracts' => $this->whenLoaded('contracts', function () {
                return $this->contracts->map(function ($contract) {
                    $status = $contract->status;

                    $statusValue = $status instanceof ContractStatus
                        ? $status->value
                        : $status;

                    $lot = $contract->lot;
                    $project = $lot?->project;

                    return [
                        'id' => $contract->id,
                        'contract_number' => $contract->contract_number,
                        'status' => $statusValue,

                        'lot' => $lot ? [
                            'id' => $lot->id,
                            'number' => $lot->number,
                            'name' => $lot->number,
                        ] : null,

                        'project' => $project ? [
                            'id' => $project->id,
                            'name' => $project->name,
                        ] : null,
                    ];
                })->values()->all();
            }),
        ];
    }
}
