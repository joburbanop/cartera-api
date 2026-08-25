<?php

namespace Database\Factories;

use App\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    public function definition(): array
    {
        $area = (string) $this->faker->randomFloat(2, 60, 250);
        $listPrice = (string) $this->faker->randomFloat(2, 150000000, 500000000);

        return [
            'project_id' => 1,
            'number' => 'L-' . $this->faker->unique()->numerify('####'),
            'area_m2' => $area,
            'price_m2' => (string) number_format((float) $listPrice / (float) $area, 2, '.', ''),
            'list_price' => $listPrice,
            'status' => 'disponible',
            'type' => 'residential',
            'folio_matricula' => $this->faker->unique()->bothify('FM-######'),
            'ficha_catastral' => $this->faker->unique()->bothify('FC-######'),
            'boundaries_north' => $this->faker->sentence(3),
            'boundaries_south' => $this->faker->sentence(3),
            'boundaries_east' => $this->faker->sentence(3),
            'boundaries_west' => $this->faker->sentence(3),
        ];
    }
}
