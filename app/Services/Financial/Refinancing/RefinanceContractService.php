<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\ContractStatus;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefinanceContractService
{
    public function __construct(
        private readonly AcuerdoPagoService $acuerdoPagoService,
        private readonly TiempoGraciaService $tiempoGraciaService,
        private readonly RefinanciarSaldoService $refinanciarSaldoService,
        private readonly ExoneracionInteresesService $exoneracionInteresesService,
    ) {}

    public function apply(Contract $contract, array $params): void
    {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::tryFrom((string) $contract->status);

        if ($status !== ContractStatus::ACTIVO) {
            throw ValidationException::withMessages([
                'contract' => 'Solo se pueden refinanciar contratos en estado activo.',
            ]);
        }

        $tipo = (string) ($params['tipo'] ?? '');
        $strategy = $this->resolveStrategy($tipo);

        $before = [
            'term_months' => $contract->term_months,
            'interest_rate' => (string) $contract->interest_rate,
        ];

        DB::transaction(function () use ($contract, $params, $tipo, $strategy, $before) {
            $strategy->apply($contract, $params);
            $contract->refresh();

            $activity = activity()
                ->performedOn($contract)
                ->withProperties([
                    'tipo' => $tipo,
                    'params' => $params,
                    'before' => $before,
                    'after' => [
                        'term_months' => $contract->term_months,
                        'interest_rate' => (string) $contract->interest_rate,
                    ],
                ]);

            if (auth()->user()) {
                $activity->causedBy(auth()->user());
            }

            $activity->log(sprintf('Refinanció el contrato mediante %s', $tipo));
        });
    }

    private function resolveStrategy(string $tipo): RefinanceStrategy
    {
        return match ($tipo) {
            'acuerdo_pago' => $this->acuerdoPagoService,
            'tiempo_gracia' => $this->tiempoGraciaService,
            'refinanciar_saldo' => $this->refinanciarSaldoService,
            'exoneracion_intereses' => $this->exoneracionInteresesService,
            default => throw ValidationException::withMessages([
                'tipo' => 'Tipo de refinanciación no soportado.',
            ]),
        };
    }
}
