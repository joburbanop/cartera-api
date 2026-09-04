<?php

namespace App\DTOs;

use App\Http\Requests\UpdateLotRequest;

class UpdateLotDTO
{
    public function __construct(
        public readonly string $number,
        public readonly float $areaM2,
        public readonly float $listPrice,
        public readonly ?string $status,
        public readonly ?string $type,
    ) {}

    public static function fromRequest(UpdateLotRequest $request): self
    {
        return new self(
            number: $request->validated('number'),
            areaM2: (float) $request->validated('area_m2'),
            listPrice: (float) $request->validated('list_price'),
            status: $request->validated('status'),
            type: $request->validated('type'),
        );
    }
}