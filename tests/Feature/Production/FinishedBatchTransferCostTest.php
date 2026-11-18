<?php

declare(strict_types=1);

use App\Enums\ProductionOrderStatus;
use App\Models\FinishedInventoryMovement;
use App\Models\FinishedProductBatch;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

// Costo de traslado del lote de PT: al completar la OP, el lote guarda su costo por unidad con el CIF del producto (la
// regla del saldo), y el movimiento de entrada sigue sin CIF.

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['job_title' => 'Jefe de calidad', 'signature_path' => 'signatures/test.png']);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->warehouse = Warehouse::factory()->factory()->create();
    $unit = UnitOfMeasure::factory()->create();

    // Granel: un químico a $5 por unidad; 52 consumidas = 260.
    $chemical = RawMaterial::factory()->create(['code' => 'RESINA', 'unit_of_measure_id' => $unit->id]);
    InventoryBatch::factory()->create([
        'raw_material_id' => $chemical->id,
        'warehouse_id' => $this->warehouse->id,
        'initial_quantity' => '100',
        'remaining_quantity' => '100',
        'unit_price' => '5',
    ]);

    // Envase con inventario a $1.000 y etiqueta sin inventario a $300.
    $container = RawMaterial::factory()->create([
        'code' => 'GALON-MET',
        'category_id' => RawMaterialCategory::factory()->container()->create()->id,
    ]);
    InventoryBatch::factory()->create([
        'raw_material_id' => $container->id,
        'warehouse_id' => $this->warehouse->id,
        'initial_quantity' => '20',
        'remaining_quantity' => '20',
        'unit_price' => '1000',
    ]);
    $this->label = RawMaterial::factory()->withoutInventoryTracking()->create([
        'code' => 'ETQ-GALON',
        'category_id' => RawMaterialCategory::factory()->label()->create()->id,
        'current_price' => '300',
    ]);

    $this->product = Product::factory()->create([
        'category_id' => ProductCategory::factory()->create()->id,
        'unit_of_measure_id' => $unit->id,
        'cif_percentage' => '15',
    ]);
    $formula = Formula::factory()->create(['product_id' => $this->product->id, 'created_by' => $this->admin->id]);
    FormulaDetail::create(['formula_id' => $formula->id, 'raw_material_id' => $chemical->id, 'quantity' => 0.5, 'unit_of_measure_id' => $unit->id]);

    $this->gallon = ProductVariant::factory()->create([
        'product_id' => $this->product->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
        'package_raw_material_id' => $container->id,
        'label_raw_material_id' => $this->label->id,
    ]);

    $this->order = ProductionOrder::factory()->create([
        'product_id' => $this->product->id,
        'formula_id' => $formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 10,
        'status' => ProductionOrderStatus::InProgress,
    ]);
    $this->detail = ProductionOrderDetail::create([
        'production_order_id' => $this->order->id,
        'raw_material_id' => $chemical->id,
        'step_order' => 1,
        'planned_quantity' => '52',
        'unit_cost' => '5',
        'total_cost' => '260',
    ]);
});

function transferCostPlanFor(ProductionOrder $order, ProductVariant $variant, int $units = 10): ProductionOrderPackagingPlan
{
    test()->post(route('production-orders.packaging-plans.store', $order), [
        'product_variant_id' => $variant->id,
        'planned_units' => $units,
    ])->assertSessionHasNoErrors();

    return ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->latest('id')->firstOrFail();
}

/**
 * @param  list<array<string, mixed>>  $packaging
 */
function completeWithPackaging(ProductionOrder $order, ProductionOrderDetail $detail, array $packaging): void
{
    test()->post(route('production-orders.complete', $order), [
        'responsible_name' => 'Operario',
        'density_kg_per_gallon' => 5,
        'quality_responsible_user_id' => test()->admin->id,
        'line_adjustment_ids' => [],
        'remnant_consumption_ids' => [],
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 52]],
        'packaging' => $packaging,
    ])->assertSessionHasNoErrors();
}

it('guarda en el lote el costo por unidad con CIF, fijo y no estimado, sin cambiar el del movimiento', function () {
    $plan = transferCostPlanFor($this->order, $this->gallon);

    // 10 galones: 7 envases nuevos (3 reutilizados) y 12 etiquetas.
    completeWithPackaging($this->order, $this->detail, [[
        'id' => $plan->id,
        'actual_units' => 10,
        'new_containers_used' => 7,
        'label_raw_material_id' => $this->label->id,
        'labels_used' => 12,
    ]]);

    // Granel 260 / 10 = 26; empaque (7 × 1.000 + 12 × 300) / 10 = 1.060; 1.086 × 1,15 = 1.248,9.
    expect(FinishedInventoryMovement::query()->where('production_order_id', $this->order->id)->sole()->cost_price)
        ->toBe('1086.0000')
        ->and(FinishedProductBatch::query()->where('production_order_id', $this->order->id)->sole())
        ->transfer_unit_cost->toBe('1248.9000')
        ->transfer_cost_estimated->toBeFalse();

    // El lote audita su costo de traslado al nacer.
    $activity = Activity::query()->where('log_name', 'lotes_producto_terminado')->where('event', 'created')->sole();
    expect($activity->properties['attributes'])
        ->toHaveKey('transfer_unit_cost', '1248.9000')
        ->toHaveKey('transfer_cost_estimated', false);
});

it('sin CIF el costo de traslado es el mismo costo, y baja en lo que no se compró de envases', function (?string $cif) {
    $this->product->update(['cif_percentage' => $cif]);
    $plan = transferCostPlanFor($this->order, $this->gallon);

    // 7 envases nuevos, sin etiqueta: 26 + 7 × 1.000 / 10 = 726 (los 3 reutilizados no cuestan).
    completeWithPackaging($this->order, $this->detail, [[
        'id' => $plan->id,
        'actual_units' => 10,
        'new_containers_used' => 7,
        'label_raw_material_id' => null,
        'labels_used' => null,
    ]]);

    expect(FinishedProductBatch::query()->where('production_order_id', $this->order->id)->sole())
        ->transfer_unit_cost->toBe('726.0000')
        ->transfer_cost_estimated->toBeFalse();
})->with([
    'CIF nulo' => [null],
    'CIF 0' => ['0'],
]);

it('con dos presentaciones en la misma OP cada lote guarda el suyo', function () {
    $bucket = ProductVariant::factory()->create([
        'product_id' => $this->product->id,
        'presentation_value' => 5,
        'presentation_label' => 'Cuñete',
        'package_raw_material_id' => null,
        'label_raw_material_id' => null,
    ]);
    $gallonPlan = transferCostPlanFor($this->order, $this->gallon, 5);
    $bucketPlan = transferCostPlanFor($this->order, $bucket, 1);

    completeWithPackaging($this->order, $this->detail, [
        ['id' => $gallonPlan->id, 'actual_units' => 5, 'new_containers_used' => 5, 'label_raw_material_id' => null, 'labels_used' => null],
        ['id' => $bucketPlan->id, 'actual_units' => 1, 'new_containers_used' => null, 'label_raw_material_id' => null, 'labels_used' => null],
    ]);

    $costByVariant = FinishedInventoryMovement::query()
        ->where('production_order_id', $this->order->id)
        ->pluck('cost_price', 'product_variant_id');
    $batches = FinishedProductBatch::query()->where('production_order_id', $this->order->id)->get()->keyBy('product_variant_id');

    // 10 galones de granel: 26 por galón. Galón: 26 + 1.000 de envase = 1.026; cuñete: 5 × 26 = 130, sin empaque.
    expect($costByVariant[$this->gallon->id])->toBe('1026.0000')
        ->and($costByVariant[$bucket->id])->toBe('130.0000')
        ->and($batches[$this->gallon->id]->transfer_unit_cost)->toBe('1179.9000')
        ->and($batches[$bucket->id]->transfer_unit_cost)->toBe('149.5000');
});
