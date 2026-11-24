<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RawMaterialFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawMaterialFamily>
 */
class RawMaterialFamilyFactory extends Factory
{
    protected $model = RawMaterialFamily::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
