<?php

namespace App\Services\Financial\Refinancing;

use App\Enums\ContractStatus;
use App\Models\Contract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefinanceContractService
{
    /** Canal de bitácora propio: permite mostrarle al administrador solo esto. */
    public const LOG_NAME = 'refinancing';

    private const LOCK_SECONDS = 30;

    private const IDEMPOTENCY_TTL_MINUTES = 10;

    public function __construct(
        private readonly AcuerdoPagoService $acuerdoPagoService,
        private readonly TiempoGraciaService $tiempoGraciaService,
        private readonly RefinanciarSaldoService $refinanciarSaldoService,
        private readonly ExoneracionInteresesService $exoneracionInteresesService,
        private readonly LiquidacionContadoService $liquidacionContadoService,
    ) {}

    /**
     * @return bool `false` cuando la petición es un reenvío de una refinanciación
     *              ya aplicada (misma clave de idempotencia) y no se hizo nada.
     */
    public function apply(Contract $contract, array $params): bool
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
        $motivo = trim((string) ($params['motivo'] ?? ''));

        $idempotencyKey = $this->idempotencyKey($params);
        $lock = Cache::lock("refinance:contract:{$contract->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'contract' => 'Hay otra refinanciación en curso para este contrato. Espere unos segundos y vuelva a intentarlo.',
            ]);
        }

        try {
            if ($idempotencyKey !== null && ! Cache::add($idempotencyKey, true, now()->addMinutes(self::IDEMPOTENCY_TTL_MINUTES))) {
                return false;
            }

            try {
                $this->run($contract, $params, $tipo, $motivo, $strategy);
            } catch (Throwable $exception) {
                if ($idempotencyKey !== null) {
                    Cache::forget($idempotencyKey);
                }

                throw $exception;
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    private function run(
        Contract $contract,
        array $params,
        string $tipo,
        string $motivo,
        RefinanceStrategy $strategy,
    ): void {
        $before = $this->contractStateOf($contract);

        DB::transaction(function () use ($contract, $params, $tipo, $motivo, $strategy, $before) {
            $affected = $strategy->affectedInstallments($contract, $params);
            $snapshot = InstallmentSnapshot::of($affected);
            $liquidationBreakdown = $tipo === 'liquidacion_contado'
                ? $this->liquidacionContadoService->breakdown($contract)
                : null;

            $strategy->apply($contract, $params);
            $contract->refresh();

            $properties = [
                'tipo' => $tipo,
                'motivo' => $motivo,
                'params' => $this->loggableParams($params),
                'before' => $before,
                'after' => $this->contractStateOf($contract),
                'installments_before' => $snapshot,
            ];

            if ($tipo === 'refinanciar_saldo') {
                $accrued = '0.00';
                foreach ($snapshot as $row) {
                    $unpaid = bcsub(
                        bcadd((string) ($row['interest_value'] ?? '0'), '0', 2),
                        bcadd((string) ($row['interest_paid'] ?? '0'), '0', 2),
                        2
                    );
                    if (bccomp($unpaid, '0.00', 2) > 0) {
                        $accrued = bcadd($accrued, $unpaid, 2);
                    }
                }

                $properties['accrued_unpaid_interest'] = $accrued;
                $properties['deferred_interest_action'] = (string) ($params['deferred_interest_action']
                    ?? (bccomp($accrued, '0.00', 2) > 0 ? '' : RefinanciarSaldoService::ACTION_CONDONAR));
                $properties['new_down_payment'] = (string) $contract->down_payment_pactada;
                $properties['new_principal'] = bcsub(
                    (string) $contract->sale_price,
                    (string) $contract->down_payment_pactada,
                    2
                );
            }

            if ($liquidationBreakdown !== null) {
                $properties = array_merge($properties, $liquidationBreakdown);
            }

            $activity = activity(self::LOG_NAME)
                ->performedOn($contract)
                ->withProperties($properties);

            if (auth()->user()) {
                $activity->causedBy(auth()->user());
            }

            $activity->log(sprintf('Refinanció el contrato mediante %s', $tipo));
        });
    }

    /**
     * @return array<string, string|int|null>
     */
    private function contractStateOf(Contract $contract): array
    {
        return [
            'term_months' => $contract->term_months,
            'interest_rate' => (string) $contract->interest_rate,
            'sale_price' => (string) $contract->sale_price,
            'down_payment_pactada' => (string) $contract->down_payment_pactada,
            'deferred_interest_balance' => (string) ($contract->deferred_interest_balance ?? '0.00'),
        ];
    }

    /**
     * El motivo se guarda aparte y la clave de idempotencia no es dato de negocio.
     */
    private function loggableParams(array $params): array
    {
        unset($params['motivo'], $params['idempotency_key']);

        return $params;
    }

    private function idempotencyKey(array $params): ?string
    {
        $key = trim((string) ($params['idempotency_key'] ?? ''));

        return $key === '' ? null : "refinance:idempotency:{$key}";
    }

    private function resolveStrategy(string $tipo): RefinanceStrategy
    {
        return match ($tipo) {
            'acuerdo_pago' => $this->acuerdoPagoService,
            'tiempo_gracia' => $this->tiempoGraciaService,
            'refinanciar_saldo' => $this->refinanciarSaldoService,
            'exoneracion_intereses' => $this->exoneracionInteresesService,
            'liquidacion_contado' => $this->liquidacionContadoService,
            default => throw ValidationException::withMessages([
                'tipo' => 'Tipo de refinanciación no soportado.',
            ]),
        };
    }
}
