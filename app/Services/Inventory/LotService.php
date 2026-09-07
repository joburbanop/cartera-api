<?php

namespace App\Services\Inventory;

use App\DTOs\CreateLotDTO;
use App\DTOs\UpdateLotDTO;
use App\Enums\AmortizationStatus;
use App\Enums\LotStatus;
use App\Enums\LotType;
use App\Models\Lot;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LotService
{
    public function createLot(CreateLotDTO $dto, int $userId): Lot
    {
        return Lot::create([
            'project_id' => $dto->projectId,
            'number' => $dto->number,
            'area_m2' => $dto->areaM2,
            'price_m2' => $dto->priceM2,
            'list_price' => $dto->listPrice,
            'status' => $dto->status ?? LotStatus::DISPONIBLE->value,
            'type' => $dto->type ?? LotType::RESIDENTIAL->value,
            'folio_matricula' => $dto->folioMatricula,
            'ficha_catastral' => $dto->fichaCatastral,
            'boundaries_north' => $dto->boundariesNorth,
            'boundaries_south' => $dto->boundariesSouth,
            'boundaries_east' => $dto->boundariesEast,
            'boundaries_west' => $dto->boundariesWest,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param array{
     *     project_id?: int|null,
     *     number?: string|null,
     *     status?: string|null,
     *     plan_type?: string|null,
     *     cartera?: string|null,
     *     customer?: string|null
     * } $filters
     */
    public function getAllLots(
        ?int $projectId = null,
        int $perPage = 20,
        array $filters = []
    ): LengthAwarePaginator {
        $query = Lot::with('project')
            ->withCount('contracts')
            ->with([
                'contracts' => function ($relation): void {
                    $relation
                        ->select('id', 'lot_id')
                        ->orderByDesc('id');
                }
            ])
            ->latest();

        $projectId = isset($filters['project_id'])
            ? $filters['project_id']
            : $projectId;

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $number = trim((string) ($filters['number'] ?? ''));

        if ($number !== '') {
            $query->where(function ($builder) use ($number) {
                $builder->where('number', $number)
                    ->orWhere('number', 'like', '%' . $number . '%');
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '' && LotStatus::tryFrom($status)) {
            $query->where('status', $status);
        }

        $planType = trim((string) ($filters['plan_type'] ?? ''));

        if ($planType === 'none') {
            $query->whereDoesntHave('contracts');
        } elseif ($planType === 'special') {
            $query->whereHas(
                'contracts',
                fn ($contracts) => $contracts->where('is_special_lot', true)
            );
        } elseif ($planType === 'custom') {
            $query->whereHas(
                'contracts',
                fn ($contracts) => $contracts->where('is_custom_plan', true)
            );
        } elseif ($planType === 'standard') {
            $query->whereHas('contracts', function ($contracts) {
                $contracts
                    ->where('is_special_lot', false)
                    ->where('is_custom_plan', false);
            });
        }

        $cartera = trim((string) ($filters['cartera'] ?? ''));

        if ($cartera === 'mora') {
            $query->whereHas(
                'contracts.installments',
                fn ($installments) => $this->overdueInstallments($installments)
            );
        } elseif ($cartera === 'al_dia') {
            $query
                ->whereHas('contracts')
                ->whereDoesntHave(
                    'contracts.installments',
                    fn ($installments) => $this->overdueInstallments($installments)
                );
        }

        $customer = trim((string) ($filters['customer'] ?? ''));

        if ($customer !== '') {
            $like = '%' . $customer . '%';

            $query->whereHas('contracts', function ($contracts) use ($like) {
                $contracts->where(function ($builder) use ($like) {
                    $builder
                        ->whereHas('customer', function ($holder) use ($like) {
                            $holder
                                ->where('name', 'like', $like)
                                ->orWhere('document_number', 'like', $like);
                        })
                        ->orWhereHas('customers', function ($holders) use ($like) {
                            $holders
                                ->where('customers.name', 'like', $like)
                                ->orWhere(
                                    'customers.document_number',
                                    'like',
                                    $like
                                );
                        });
                });
            });
        }

        return $query->paginate($perPage);
    }

    private function overdueInstallments(Builder $query): Builder
    {
        return $query
            ->where('installment_number', '>', 0)
            ->where(
                'status',
                '!=',
                AmortizationStatus::PAID->value
            )
            ->whereDate(
                'due_date',
                '<=',
                Carbon::today()->toDateString()
            );
    }

    public function updateLot(
        Lot $lot,
        UpdateLotDTO $dto,
        int $userId
    ): Lot {
        if ($lot->status !== LotStatus::DISPONIBLE) {
            throw new \DomainException(
                'Solo se pueden editar lotes disponibles.'
            );
        }

        $priceM2 = $dto->areaM2 > 0
            ? $dto->listPrice / $dto->areaM2
            : 0;

        $lot->update([
            'number' => $dto->number,
            'area_m2' => $dto->areaM2,
            'list_price' => $dto->listPrice,
            'price_m2' => $priceM2,
            'status' => $dto->status,
            'type' => $dto->type,
            'updated_by' => $userId,
        ]);

        return $lot->load('project');
    }

    public function archiveLot(Lot $lot, int $userId): void
    {
        if ($lot->contracts()->exists()) {
            throw new \DomainException(
                'No se puede archivar un lote vinculado a un contrato.'
            );
        }

        if ($lot->status !== LotStatus::DISPONIBLE) {
            throw new \DomainException(
                'Solo se pueden archivar lotes disponibles.'
            );
        }

        $lot->update([
            'deleted_by' => $userId,
        ]);

        $lot->delete();
    }

    public function activateLot(Lot $lot, int $userId): Lot
    {
        if (!$lot->trashed()) {
            throw new \DomainException(
                'El lote no está archivado.'
            );
        }

        $lot->restore();

        $lot->update([
            'updated_by' => $userId,
        ]);

        return $lot->load('project');
    }

    public function getArchivedLots(?int $projectId = null)
    {
        $query = Lot::onlyTrashed()
            ->with('project')
            ->latest('deleted_at');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $query->get();
    }
}