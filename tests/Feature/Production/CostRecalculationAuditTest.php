<?php

declare(strict_types=1);

use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\ProductionCostRecalculationService;
use Spatie\Activitylog\Models\Activity;

// El recálculo de costos guarda el producto y sus presentaciones: la auditoría solo debe registrar lo que cambió. Con
// filas leídas a medias registraba como cambio cada atributo que no se leyó («categoría: vacío → 1»).

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $unit = UnitOfMeasure::factory()->create();
    $this->chemical = RawMaterial::factory()->create(['unit_of_measure_id' => $unit->id, 'current_price' => '10']);
    $this->container = RawMaterial::factory()->create([
        'category_id' => RawMaterialCategory::factory()->container()->create()->id,
        'current_price' => '1000',
    ]);

    $this->product = Product::factory()->create([
        'category_id' => ProductCategory::factory()->create()->id,
        'unit_of_measure_id' => $unit->id,
        'cif_percentage' => '20',
        'reference_density_kg_per_gallon' => '4.2',
        'is_active' => true,
    ]);
    $formula = Formula::factory()->create(['product_id' => $this->product->id, 'is_price_reference' => true, 'is_active' => true]);
    FormulaDetail::create(['formula_id' => $formula->id, 'raw_material_id' => $this->chemical->id, 'quantity' => '2', 'unit_of_measure_id' => $unit->id]);

    foreach ([[1, 'Galón'], [5, 'Cuñete']] as [$value, $label]) {
        ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'presentation_value' => $value,
            'presentation_label' => $label,
            'package_raw_material_id' => $this->container->id,
            'label_raw_material_id' => null,
        ]);
    }

    Activity::query()->delete();
});

/**
 * El recálculo solo cambia costos y precios: el producto no audita ninguno de los dos, así que no deja registro, y cada
 * presentación registra solo su costo o su precio, con el valor que tenía antes. Un atributo leído a medias aparecería
 * como «vacío → valor» (código, activo, categoría…).
 */
function expectOnlyCostChanges(): void
{
    expect(Activity::query()->where('log_name', 'productos')->where('event', 'updated')->exists())->toBeFalse();

    $updates = Activity::query()->where('log_name', 'variantes_producto')->where('event', 'updated')->get();
    expect($updates)->toHaveCount(2);

    foreach ($updates as $activity) {
        expect($activity->properties['attributes'])->not->toBeEmpty()
            ->and(array_diff(array_keys($activity->properties['attributes']), ['current_cost', 'current_price']))->toBeEmpty()
            ->and($activity->properties['old'])->each->not->toBeNull();
    }
}

it('recalcular un producto no registra en la auditoría atributos que no cambiaron', function () {
    app(ProductionCostRecalculationService::class)->recalculateForProduct((int) $this->product->id, forcePriceRefresh: true);

    expectOnlyCostChanges();
});

it('recalcular por el precio de un envase no registra atributos que no cambiaron', function () {
    $service = app(ProductionCostRecalculationService::class);
    $service->recalculateForProduct((int) $this->product->id, forcePriceRefresh: true);
    Activity::query()->delete();

    $this->container->update(['current_price' => '1200']);
    Activity::query()->delete();

    $service->recalculateForRawMaterial((int) $this->container->id);

    expectOnlyCostChanges();
});

it('reprecia las presentaciones de un producto sin registrar atributos que no cambiaron', function () {
    $service = app(ProductionCostRecalculationService::class);
    $service->recalculateForProduct((int) $this->product->id, forcePriceRefresh: true);
    $this->product->update(['cif_percentage' => '30']);
    Activity::query()->delete();

    $service->repriceVariantsOfProduct($this->product->fresh(), forcePriceRefresh: true);

    expectOnlyCostChanges();
});
