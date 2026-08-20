<?php

namespace App\DTOs;

use App\Http\Requests\StoreLotRequest;

class CreateLotDTO
{
    public function __construct(
        public readonly int $projectId,
        public readonly string $number,
        public readonly float $areaM2,
        public readonly float $priceM2,
        public readonly float $listPrice,
        public readonly ?string $status,
        public readonly ?string $type,
        public readonly ?string $folioMatricula,
        public readonly ?string $fichaCatastral,
        public readonly ?string $boundariesNorth,
        public readonly ?string $boundariesSouth,
        public readonly ?string $boundariesEast,
        public readonly ?string $boundariesWest
    ) {}

    public static function fromRequest(StoreLotRequest $request): self
    {
        return new self(
            projectId: (int) $request->validated('project_id'),
            number: $request->validated('number'),
            areaM2: (float) $request->validated('area_m2'),
            priceM2: (float) $request->validated('price_m2'),
            listPrice: (float) $request->validated('list_price'),
            status: $request->validated('status'),
            type: $request->validated('type'),
            folioMatricula: $request->validated('folio_matricula'),
            fichaCatastral: $request->validated('ficha_catastral'),
            boundariesNorth: $request->validated('boundaries_north'),
            boundariesSouth: $request->validated('boundaries_south'),
            boundariesEast: $request->validated('boundaries_east'),
            boundariesWest: $request->validated('boundaries_west')
        );
    }
}