<?php

namespace App\Services\Inventory;

use App\DTOs\CreateLotDTO;
use App\Models\Lot;
use App\Enums\LotStatus;
use App\Enums\LotType;

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

    public function getAllLots(?int $projectId = null, int $perPage = 15)
    {
        $query = Lot::with('project')->latest();

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $query->get();
    }
}