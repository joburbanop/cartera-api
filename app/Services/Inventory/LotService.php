<?php

namespace App\Services\Inventory;

use App\DTOs\CreateLotDTO;
use App\DTOs\UpdateLotDTO;
use App\Enums\LotStatus;
use App\Models\Lot;


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
            'type' => $dto->type,
            'folio_matricula' => $dto->folioMatricula,
            'ficha_catastral' => $dto->fichaCatastral,
            'boundaries_north' => $dto->boundariesNorth,
            'boundaries_south' => $dto->boundariesSouth,
            'boundaries_east' => $dto->boundariesEast,
            'boundaries_west' => $dto->boundariesWest,
            'created_by' => $userId,
        ]);
    }

    public function getAllLots(?int $projectId = null, int $perPage = 15)
    {
        $query = Lot::with('project')->latest();

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $query->get();
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