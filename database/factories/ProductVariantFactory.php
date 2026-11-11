<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RawMaterialType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'code' => $this->faker->unique()->numerify('########'),
            'unit_of_measure_id' => UnitOfMeasure::where('symbol', 'gl')->first()?->id ?? UnitOfMeasure::factory(),
            'presentation_value' => $this->faker->randomElement([1, 5, 20, 0.25]),
            // Única: la presentación no se repite dentro de un producto (índice único), y dos variantes de la fábrica
            // pueden compartir producto.
            'presentation_label' => $this->faker->unique()->numerify('Presentación #####'),
            'current_cost' => $this->faker->randomFloat(4, 10, 100),
            'current_price' => $this->faker->randomFloat(4, 15, 150),
            'package_raw_material_id' => RawMaterial::whereHas('category', function ($query) {
                $query->where('type', RawMaterialType::Container->value);
            })->inRandomOrder()->first()?->id,
            'is_active' => true,
        ];
    }
}
