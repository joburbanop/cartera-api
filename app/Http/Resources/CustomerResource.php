<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Obtener el contrato activo si existe
        $activeContract = $this->activeContract;
        
        $loteName = null;
        $estadoCartera = 'sin_contrato';

        if ($activeContract) {
            // Obtener el nombre del lote
            $loteName = $activeContract->lot ? $activeContract->lot->name : 'Sin lote';
            
            // Verificar si tiene promesas de pago vencidas y no pagadas
            $hasOverduePromises = $activeContract->paymentPromises()
                ->where('is_paid', false)
                ->whereDate('expected_date', '<', Carbon::now())
                ->exists();
            
            // Si no hay promesas vencidas, verificar cuotas de amortización vencidas
            if (!$hasOverduePromises) {
                $hasOverdueInstallments = $activeContract->installments()
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->whereDate('due_date', '<', Carbon::now())
                    ->exists();
                
                $estadoCartera = $hasOverdueInstallments ? 'vencida' : 'al_dia';
            } else {
                $estadoCartera = 'vencida';
            }
        }

        return [
            'id' => $this->id,
            'nombre' => $this->name,
            'name' => $this->name, // Alias para compatibilidad con contracts
            'documento' => $this->document_number,
            'document_number' => $this->document_number, // Alias para compatibilidad con contracts
            'telefono' => $this->phone ?? 'Sin teléfono',
            'phone' => $this->phone, // Alias para compatibilidad con contracts
            'email' => $this->email,
            'lote' => $loteName,
            'estadoCartera' => $estadoCartera,
            'tipo_documento' => $this->document_type?->value ?? 'CC',
            'document_type' => $this->document_type?->value ?? 'CC', // Alias para compatibilidad
            'direccion' => $this->address,
            'address' => $this->address, // Alias para compatibilidad
            'ciudad' => $this->city,
            'city' => $this->city, // Alias para compatibilidad
        ];
    }
}
