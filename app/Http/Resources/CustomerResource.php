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
            'nombre' => $this->name,
            'name' => $this->name,
            'documento' => $this->document_number,
            'document_number' => $this->document_number,
            'telefono' => $this->phone ?? 'Sin teléfono',
            'phone' => $this->phone,
            'email' => $this->email,
            'lote' => $loteName,
            'cantidad_contratos' => $cantidadContratos,
            'estadoCartera' => $estadoCartera,
            'tipo_documento' => $this->document_type?->value ?? 'CC',
            'document_type' => $this->document_type?->value ?? 'CC',
            'direccion' => $this->address,
            'address' => $this->address,
            'ciudad' => $this->city,
            'city' => $this->city,
        ];
    }
}
