<?php

declare(strict_types=1);

use App\Actions\Production\BuildProductionOrderExportDataAction;
use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductionRemnant;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RemnantConsumption;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Production\RemnantDrawdown;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'job_title' => 'Analista de Calidad',
        'signature_path' => 'signatures/test.png',
    ]);
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $category = ProductCategory::create(['name' => 'Test Category']);
    $uom = UnitOfMeasure::create(['code' => 'gal', 'name' => 'Galon', 'symbol' => 'gal']);

    $this->sourceProduct = Product::create([
        'name' => 'Source Product',
        'category_id' => $category->id,
        'unit_of_measure_id' => $uom->id,
        'current_cost' => 10,
        'cif_percentage' => 50,
        'current_price' => 15,
        'price_threshold' => 5,
    ]);

    $this->targetProduct = Product::create([
        'name' => 'Target Product',
        'category_id' => $category->id,
        'unit_of_measure_id' => $uom->id,
        'current_cost' => 12,
        'cif_percentage' => 40,
        'current_price' => 16.8,
        'price_threshold' => 5,
    ]);

    $formula = Formula::create([
        'product_id' => $this->sourceProduct->id,
        'version' => 1,
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $this->material = RawMaterial::create([
        'code' => 'RM-CONSUME-'.uniqid(),
        'unit_of_measure_id' => $uom->id,
        'current_price' => 5000,
    ]);

    FormulaDetail::create([
        'formula_id' => $formula->id,
        'raw_material_id' => $this->material->id,
        'quantity' => 0.5,
        'unit_of_measure_id' => $uom->id,
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'Planta Consume Test',
        'city' => 'Cali',
        'type' => 'factory',
        'is_active' => true,
    ]);

    $this->batch = InventoryBatch::create([
        'raw_material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'initial_quantity' => 200,
        'remaining_quantity' => 200,
        'unit_price' => 5,
        'entry_date' => now(),
    ]);

    // Source order — completed, with a remnant
    $this->sourceOrder = ProductionOrder::create([
        'order_number' => 'OP-SOURCE-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->sourceProduct->id,
        'formula_id' => $formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 100,
        'status' => ProductionOrderStatus::Completed->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
        'density_kg_per_gallon' => 5,
    ]);

    ProductionOrderDetail::create([
        'production_order_id' => $this->sourceOrder->id,
        'raw_material_id' => $this->material->id,
        'batch_id' => $this->batch->id,
        'planned_quantity' => 50,
        'unit_cost' => 5,
        'total_cost' => 250,
    ]);

    $this->remnant = ProductionRemnant::create([
        'source_order_id' => $this->sourceOrder->id,
        'product_id' => $this->sourceProduct->id,
        'warehouse_id' => $this->warehouse->id,
        'original_quantity_gallons' => 10,
        'original_quantity_kg' => 50,
        'available_quantity_gallons' => 10,
        'available_quantity_kg' => 50,
        'density_kg_per_gallon' => 5,
        'cost_per_gallon' => 5.5,
        'status' => RemnantStatus::Available,
        'created_by' => $this->user->id,
    ]);

    // Another source order + remnant (for FIFO testing)
    $this->sourceOrder2 = ProductionOrder::create([
        'order_number' => 'OP-SOURCE2-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->sourceProduct->id,
        'formula_id' => $formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 50,
        'status' => ProductionOrderStatus::Completed->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
        'density_kg_per_gallon' => 5,
    ]);

    $this->remnant2 = ProductionRemnant::create([
        'source_order_id' => $this->sourceOrder2->id,
        'product_id' => $this->sourceProduct->id,
        'warehouse_id' => $this->warehouse->id,
        'original_quantity_gallons' => 5,
        'original_quantity_kg' => 25,
        'available_quantity_gallons' => 5,
        'available_quantity_kg' => 25,
        'density_kg_per_gallon' => 5,
        'cost_per_gallon' => 5.5,
        'status' => RemnantStatus::Available,
        'created_by' => $this->user->id,
    ]);

    // Target order — InProgress, different product (to verify cross-product works)
    $targetFormula = Formula::create([
        'product_id' => $this->targetProduct->id,
        'version' => 1,
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    FormulaDetail::create([
        'formula_id' => $targetFormula->id,
        'raw_material_id' => $this->material->id,
        'quantity' => 0.3,
        'unit_of_measure_id' => $uom->id,
    ]);

    $this->targetOrder = ProductionOrder::create([
        'order_number' => 'OP-TARGET-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->targetProduct->id,
        'formula_id' => $targetFormula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 50,
        'status' => ProductionOrderStatus::InProgress->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
    ]);
});

/** Planea un saldo en una orden con la petición de la pantalla. */
function planRemnant(object $test, ProductionOrder $order, ProductionRemnant $remnant, string|int|float $kilos, ?string $notes = null): TestResponse
{
    return $test->post(route('production-orders.consume-remnant', $order), array_filter([
        'remnant_id' => $remnant->id,
        'quantity_kg' => $kilos,
        'notes' => $notes,
    ], fn ($value) => $value !== null));
}

/** Otra orden abierta de la misma bodega, para probar lo que reservan dos órdenes a la vez. */
function otherOpenOrder(object $test, ProductionOrderStatus $status = ProductionOrderStatus::Pending): ProductionOrder
{
    return ProductionOrder::create([
        'order_number' => 'OP-OTHER-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $test->targetProduct->id,
        'formula_id' => $test->targetOrder->formula_id,
        'warehouse_id' => $test->warehouse->id,
        'quantity' => 20,
        'status' => $status->value,
        'planned_date' => now(),
        'created_by' => $test->user->id,
    ]);
}

/** Completa la orden destino con un ingrediente y una presentación, enviando los saldos que muestra la pantalla. */
function completeTargetOrder(object $test): TestResponse
{
    $detail = ProductionOrderDetail::create([
        'production_order_id' => $test->targetOrder->id,
        'raw_material_id' => $test->material->id,
        'batch_id' => $test->batch->id,
        'planned_quantity' => 30,
        'unit_cost' => 5,
        'total_cost' => 150,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $test->targetProduct->id,
        'code' => 'VAR-'.uniqid(),
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
        'is_active' => true,
    ]);

    $plan = ProductionOrderPackagingPlan::create([
        'production_order_id' => $test->targetOrder->id,
        'product_variant_id' => $variant->id,
        'planned_units' => 10,
    ]);

    return $test->post(route('production-orders.complete', $test->targetOrder), [
        'density_kg_per_gallon' => 5,
        'quality_responsible_user_id' => $test->user->id,
        'line_adjustment_ids' => [],
        // Los saldos que muestra la pantalla.
        'remnant_consumption_ids' => RemnantConsumption::query()->where('target_order_id', $test->targetOrder->id)->pluck('id')->all(),
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 30]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
    ]);
}

/** Saldo pesado en kilos: 1,0001 kg a 4,7 kg/gal son 0,2128 gal. */
function weighedRemnant(object $test, string $kilos = '1.0001', string $gallons = '0.2128', string $density = '4.7'): ProductionRemnant
{
    $test->remnant->update([
        'original_quantity_gallons' => $gallons,
        'available_quantity_gallons' => $gallons,
        'original_quantity_kg' => $kilos,
        'available_quantity_kg' => $kilos,
        'density_kg_per_gallon' => $density,
        'cost_per_gallon' => '100',
    ]);

    return $test->remnant;
}

// --- Planear ---

it('plans a remnant without touching it, with the order pending or in progress', function (ProductionOrderStatus $status) {
    $this->targetOrder->update(['status' => $status]);

    planRemnant($this, $this->targetOrder, $this->remnant, 20, 'Consumo parcial')
        ->assertRedirect()
        ->assertInertiaFlash('success', 'Saldo agregado a la orden.');

    $this->remnant->refresh();
    $line = $this->remnant->consumptions()->firstOrFail();

    expect($this->remnant->available_quantity_kg)->toBe('50.0000')
        ->and($this->remnant->available_quantity_gallons)->toBe('10.0000')
        ->and($this->remnant->status)->toBe(RemnantStatus::Available)
        ->and((int) $line->target_order_id)->toBe($this->targetOrder->id)
        ->and($line->quantity_kg)->toBe('20.0000')
        // Estimado: 20 kg a 5 kg/gal. El costo se calcula al completar.
        ->and($line->quantity_gallons)->toBe('4.0000')
        ->and($line->consumed_cost)->toBeNull()
        ->and($line->applied_at)->toBeNull()
        ->and((int) $line->consumed_by)->toBe($this->user->id)
        ->and($line->consumed_at)->not->toBeNull()
        ->and($line->notes)->toBe('Consumo parcial');
})->with([
    'pendiente' => [ProductionOrderStatus::Pending],
    'en curso' => [ProductionOrderStatus::InProgress],
]);

it('allows planning a remnant of a different product', function () {
    // El saldo es de sourceProduct y la orden de targetProduct.
    planRemnant($this, $this->targetOrder, $this->remnant, 15)->assertSessionHasNoErrors();

    expect($this->remnant->consumptions()->count())->toBe(1);
});

it('validates remnant must be available or partially consumed', function () {
    $this->remnant->update([
        'available_quantity_gallons' => 0,
        'available_quantity_kg' => 0,
        'status' => RemnantStatus::Consumed,
    ]);

    planRemnant($this, $this->targetOrder, $this->remnant, 5)->assertSessionHasErrors(['remnant_id']);
});

it('does not plan remnants in a completed or cancelled order', function (ProductionOrderStatus $status) {
    $this->targetOrder->update(['status' => $status]);

    planRemnant($this, $this->targetOrder, $this->remnant, 5)->assertForbidden();
    $this->getJson(route('production-orders.available-remnants', $this->targetOrder))->assertForbidden();

    expect(RemnantConsumption::query()->count())->toBe(0);
})->with([
    'completada' => [ProductionOrderStatus::Completed],
    'cancelada' => [ProductionOrderStatus::Cancelled],
]);

it('in review only who can review the order plans remnants', function () {
    $this->targetOrder->update(['status' => ProductionOrderStatus::PendingReview]);

    // El admin puede completar: sigue operando la orden en revisión, como con los ajustes de línea.
    planRemnant($this, $this->targetOrder, $this->remnant, 5)->assertSessionHasNoErrors();

    $operator = User::factory()->create(['email_verified_at' => now()]);
    $operator->assignRole(SystemRole::Operator->value);

    $this->actingAs($operator);
    planRemnant($this, $this->targetOrder, $this->remnant, 5)->assertForbidden();
    $this->getJson(route('production-orders.available-remnants', $this->targetOrder))->assertForbidden();

    expect(RemnantConsumption::query()->count())->toBe(1);
});

it('validates quantity must be greater than zero', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 0)->assertSessionHasErrors(['quantity_kg']);
});

it('validates cannot plan more than the free kilos', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 100)
        ->assertSessionHasErrors(['quantity_kg' => 'No hay suficiente saldo libre. Libre: 50 kg.']);
});

it('validates warehouse mismatch', function () {
    $otherWarehouse = Warehouse::create([
        'name' => 'Otra Bodega',
        'city' => 'Cali',
        'type' => 'factory',
        'is_active' => true,
    ]);

    $otherWarehouseOrder = ProductionOrder::create([
        'order_number' => 'OP-OTHER-WH-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->targetProduct->id,
        'formula_id' => Formula::create([
            'product_id' => $this->targetProduct->id,
            'version' => 2,
            'is_active' => true,
            'created_by' => $this->user->id,
        ])->id,
        'warehouse_id' => $otherWarehouse->id,
        'quantity' => 10,
        'status' => ProductionOrderStatus::InProgress->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
    ]);

    planRemnant($this, $otherWarehouseOrder, $this->remnant, 5)->assertSessionHasErrors(['remnant_id']);
});

it('rechaza una cantidad que no alcanza 0,0001 gal', function () {
    $remnant = weighedRemnant($this, '2.5', '0.5319');

    planRemnant($this, $this->targetOrder, $remnant, '0.0001')
        ->assertSessionHasErrors(['quantity_kg' => 'La cantidad es demasiado pequeña para registrarla.']);

    expect($remnant->consumptions()->count())->toBe(0);
});

it('ignora los galones que envíe el cliente al planear', function () {
    $remnant = weighedRemnant($this);

    $this->post(route('production-orders.consume-remnant', $this->targetOrder), [
        'remnant_id' => $remnant->id,
        'quantity_gallons' => '0.2',
    ])->assertSessionHasErrors(['quantity_kg' => 'El campo kilos a usar es obligatorio.']);

    $this->post(route('production-orders.consume-remnant', $this->targetOrder), [
        'remnant_id' => $remnant->id,
        'quantity_kg' => '0.5',
        'quantity_gallons' => '0.2',
    ])->assertSessionHasNoErrors();

    expect($remnant->consumptions()->firstOrFail()->quantity_gallons)->toBe('0.1064');
});

// --- Lo libre: dos órdenes no planean el mismo saldo ---

it('two open orders cannot plan together more kilos than the remnant has', function () {
    $other = otherOpenOrder($this);

    planRemnant($this, $this->targetOrder, $this->remnant, 30)->assertSessionHasNoErrors();
    planRemnant($this, $other, $this->remnant, '20.0001')
        ->assertSessionHasErrors(['quantity_kg' => 'No hay suficiente saldo libre. Libre: 20 kg.']);
    planRemnant($this, $other, $this->remnant, 20)->assertSessionHasNoErrors();

    // Nada libre: ni la misma orden puede agregar más.
    planRemnant($this, $this->targetOrder, $this->remnant, '0.0001')->assertSessionHasErrors(['quantity_kg']);

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000')
        ->and(RemnantConsumption::query()->planned()->sum('quantity_kg'))->toEqual(50);
});

it('a cancelled order frees what it had planned', function () {
    $other = otherOpenOrder($this);
    planRemnant($this, $other, $this->remnant, 50)->assertSessionHasNoErrors();
    planRemnant($this, $this->targetOrder, $this->remnant, 1)->assertSessionHasErrors(['quantity_kg']);

    $this->post(route('production-orders.cancel', $other), ['reason' => 'Ya no se va a producir'])->assertSessionHasNoErrors();

    planRemnant($this, $this->targetOrder, $this->remnant, 50)->assertSessionHasNoErrors();
});

it('offers the free kilos and leaves out the remnants with nothing free', function () {
    $other = otherOpenOrder($this, ProductionOrderStatus::InProgress);
    planRemnant($this, $other, $this->remnant, 20)->assertSessionHasNoErrors();
    planRemnant($this, $other, $this->remnant2, 25)->assertSessionHasNoErrors();

    $remnants = $this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json();

    expect($remnants)->toHaveCount(1)
        ->and($remnants[0]['id'])->toBe($this->remnant->id)
        ->and($remnants[0]['available_quantity_kg'])->toBe('30.0000');
});

it('offers every remnant of the warehouse, oldest first, with no cut at 50', function () {
    $remnantIds = [$this->remnant->id, $this->remnant2->id];

    foreach (range(1, 53) as $day) {
        $source = ProductionOrder::factory()->completed()->create([
            'product_id' => $this->sourceProduct->id,
            'formula_id' => $this->sourceOrder->formula_id,
            'warehouse_id' => $this->warehouse->id,
        ]);
        $remnant = ProductionRemnant::factory()->create([
            'source_order_id' => $source->id,
            'product_id' => $this->sourceProduct->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);
        $remnant->forceFill(['created_at' => now()->addDays($day)])->save();
        $remnantIds[] = $remnant->id;
    }

    $offered = collect($this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json())
        ->pluck('id')
        ->all();

    expect($offered)->toHaveCount(55)
        ->and($offered)->toBe($remnantIds);
});

it('consumes in FIFO order via json endpoint', function () {
    $remnants = $this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json();

    expect($remnants)->toHaveCount(2)
        ->and($remnants[0]['id'])->toBe($this->remnant->id)
        ->and($remnants[1]['id'])->toBe($this->remnant2->id);
});

it('returns curated fields in available remnants json', function () {
    $remnant = $this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json()[0];

    expect($remnant)->toHaveKey('id')
        ->toHaveKey('source_order_number')
        ->toHaveKey('available_quantity_kg')
        ->not->toHaveKey('available_quantity_gallons')
        ->not->toHaveKey('density_kg_per_gallon')
        ->not->toHaveKey('cost_per_gallon')
        ->not->toHaveKey('product_id')
        ->not->toHaveKey('notes');
});

it('excludes consumed remnants from json endpoint', function () {
    $this->remnant->update([
        'available_quantity_gallons' => 0,
        'available_quantity_kg' => 0,
        'status' => RemnantStatus::Consumed,
    ]);

    $remnants = $this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json();

    expect($remnants)->toHaveCount(1)
        ->and($remnants[0]['id'])->toBe($this->remnant2->id);
});

it('envía los kilos libres como texto', function () {
    $remnant = $this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json()[0];

    expect($remnant['available_quantity_kg'])->toBe('50.0000');
});

// --- Quitar ---

it('removes a planned line while the order is open, without touching the remnant', function (ProductionOrderStatus $status) {
    $this->targetOrder->update(['status' => $status]);
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    $line = $this->remnant->consumptions()->firstOrFail();

    $this->delete(route('production-orders.remnant-consumptions.destroy', [$this->targetOrder, $line]))
        ->assertRedirect()
        ->assertInertiaFlash('success', 'Saldo quitado de la orden.');

    expect(RemnantConsumption::query()->count())->toBe(0)
        ->and($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000');
})->with([
    'pendiente' => [ProductionOrderStatus::Pending],
    'en curso' => [ProductionOrderStatus::InProgress],
    'en revisión' => [ProductionOrderStatus::PendingReview],
]);

it('does not remove a line that was already drawn down', function () {
    $line = RemnantConsumption::create([
        'remnant_id' => $this->remnant->id, 'target_order_id' => $this->targetOrder->id, 'quantity_kg' => '5',
        'quantity_gallons' => '1', 'consumed_cost' => '5.5', 'consumed_by' => $this->user->id,
        'consumed_at' => now(), 'applied_at' => now(),
    ]);

    $this->delete(route('production-orders.remnant-consumptions.destroy', [$this->targetOrder, $line]))
        ->assertSessionHasErrors(['remnant_consumption' => 'Este saldo ya se descontó del inventario y no se puede quitar.']);

    expect($line->fresh())->not->toBeNull();
});

it('does not remove a line without permission or from a closed order', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    $line = $this->remnant->consumptions()->firstOrFail();

    $commercial = User::factory()->create(['email_verified_at' => now()]);
    $commercial->assignRole(SystemRole::Commercial->value);
    $this->actingAs($commercial)
        ->delete(route('production-orders.remnant-consumptions.destroy', [$this->targetOrder, $line]))
        ->assertForbidden();

    $this->targetOrder->update(['status' => ProductionOrderStatus::Cancelled]);
    $this->actingAs($this->user)
        ->delete(route('production-orders.remnant-consumptions.destroy', [$this->targetOrder, $line]))
        ->assertForbidden();

    expect($line->fresh())->not->toBeNull();
});

it('answers 404 when the line belongs to another order', function () {
    $other = otherOpenOrder($this);
    planRemnant($this, $other, $this->remnant, 20)->assertSessionHasNoErrors();
    $line = $this->remnant->consumptions()->firstOrFail();

    $this->delete(route('production-orders.remnant-consumptions.destroy', [$this->targetOrder, $line]))->assertNotFound();

    expect($line->fresh())->not->toBeNull();
});

// --- Completar: el saldo se descuenta aquí ---

it('completing the order draws down the remnant and records the real gallons and cost', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();

    completeTargetOrder($this)->assertRedirect()->assertSessionHasNoErrors();

    $this->remnant->refresh();
    $line = $this->remnant->consumptions()->firstOrFail();

    expect($this->remnant->available_quantity_kg)->toBe('30.0000')
        ->and($this->remnant->available_quantity_gallons)->toBe('6.0000')
        ->and($this->remnant->status)->toBe(RemnantStatus::PartiallyConsumed)
        ->and($line->quantity_kg)->toBe('20.0000')
        ->and($line->quantity_gallons)->toBe('4.0000')
        // 4 gal × $5,5
        ->and($line->consumed_cost)->toBe('22.0000')
        ->and($line->applied_at)->not->toBeNull()
        // Costo a granel: 150 de ingredientes + 22 del saldo.
        ->and(round((float) ProductionCost::where('production_order_id', $this->targetOrder->id)->value('cost'), 2))->toBe(172.0);
});

it('completing with the whole remnant leaves it consumed', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 50)->assertSessionHasNoErrors();

    completeTargetOrder($this)->assertSessionHasNoErrors();

    $this->remnant->refresh();

    expect($this->remnant->available_quantity_kg)->toBe('0.0000')
        ->and($this->remnant->available_quantity_gallons)->toBe('0.0000')
        ->and($this->remnant->status)->toBe(RemnantStatus::Consumed);
});

it('stores null consumed_cost when remnant has no cost_per_gallon', function () {
    $this->remnant->update(['cost_per_gallon' => null]);
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();

    completeTargetOrder($this)->assertSessionHasNoErrors();

    $line = $this->remnant->consumptions()->firstOrFail();

    expect($line->consumed_cost)->toBeNull()
        ->and($line->applied_at)->not->toBeNull();
});

it('dos líneas hasta agotarlo dejan el saldo en 0 exacto y suman los galones originales', function (string $total, string $gallons, string $density, array $kilosPerLine, array $expectedGallons) {
    $remnant = weighedRemnant($this, $total, $gallons, $density);

    foreach ($kilosPerLine as $kilos) {
        planRemnant($this, $this->targetOrder, $remnant, $kilos)->assertSessionHasNoErrors();
    }

    completeTargetOrder($this)->assertSessionHasNoErrors();

    $remnant->refresh();
    $lines = $remnant->consumptions()->orderBy('id')->get();

    expect($remnant->available_quantity_kg)->toBe('0.0000')
        ->and($remnant->available_quantity_gallons)->toBe('0.0000')
        ->and($remnant->status)->toBe(RemnantStatus::Consumed)
        ->and($lines->pluck('quantity_gallons')->all())->toBe($expectedGallons)
        ->and(bcadd($lines[0]->quantity_gallons, $lines[1]->quantity_gallons, 4))->toBe($gallons)
        ->and(bcadd($lines[0]->quantity_kg, $lines[1]->quantity_kg, 4))->toBe(bcadd($total, '0', 4));
})->with([
    'un saldo de 1,0001 kg' => ['1.0001', '0.2128', '4.7', ['0.5', '0.5001'], ['0.1064', '0.1064']],
    // Dividir cada línea por separado daría 0,1667 + 0,1667 = 0,3334: uno más de los que había.
    'un redondeo que no cuadra por separado' => ['1', '0.3333', '3', ['0.5', '0.5'], ['0.1666', '0.1667']],
]);

it('un resto de menos de 0,0001 gal deja el saldo consumido con todos los kilos que había', function () {
    $remnant = weighedRemnant($this, '2.5', '0.5319');
    planRemnant($this, $this->targetOrder, $remnant, '2.4999')->assertSessionHasNoErrors();

    completeTargetOrder($this)->assertSessionHasNoErrors();

    $remnant->refresh();
    $line = $remnant->consumptions()->firstOrFail();

    expect($remnant->available_quantity_kg)->toBe('0.0000')
        ->and($remnant->available_quantity_gallons)->toBe('0.0000')
        ->and($remnant->status)->toBe(RemnantStatus::Consumed)
        ->and($line->quantity_kg)->toBe('2.5000')
        ->and($line->quantity_gallons)->toBe('0.5319');
});

it('un consumo parcial baja los kilos y recalcula los galones de los que quedan', function () {
    $remnant = weighedRemnant($this);
    planRemnant($this, $this->targetOrder, $remnant, '0.5')->assertSessionHasNoErrors();

    completeTargetOrder($this)->assertSessionHasNoErrors();

    $remnant->refresh();
    $line = $remnant->consumptions()->firstOrFail();

    expect($remnant->available_quantity_kg)->toBe('0.5001')
        ->and($remnant->available_quantity_gallons)->toBe('0.1064')
        ->and($remnant->status)->toBe(RemnantStatus::PartiallyConsumed)
        ->and($line->quantity_kg)->toBe('0.5000')
        ->and($line->quantity_gallons)->toBe('0.1064')
        // 0,1064 gal × $100
        ->and($line->consumed_cost)->toBe('10.6400');
});

it('does not complete the order when a planned remnant no longer has enough, and changes nothing', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    // Caso raro forzado: el saldo bajó por fuera de lo planeado.
    $this->remnant->update(['available_quantity_kg' => '10', 'available_quantity_gallons' => '2', 'status' => RemnantStatus::PartiallyConsumed]);

    completeTargetOrder($this)->assertSessionHasErrors([
        'remnant_consumption_ids' => "El saldo de {$this->sourceOrder->order_number} ya no alcanza: quedan 10 kg y la orden planeó 20 kg. Quita la línea y vuelve a agregarla con lo que queda.",
    ]);

    $this->remnant->refresh();

    expect($this->targetOrder->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and($this->remnant->available_quantity_kg)->toBe('10.0000')
        ->and($this->remnant->consumptions()->firstOrFail()->applied_at)->toBeNull()
        ->and(ProductionCost::where('production_order_id', $this->targetOrder->id)->exists())->toBeFalse()
        ->and($this->batch->fresh()->remaining_quantity)->toBe('200.0000');
});

it('a line drawn down before planning existed only adds its cost when completing', function () {
    RemnantConsumption::create([
        'remnant_id' => $this->remnant->id, 'target_order_id' => $this->targetOrder->id, 'quantity_kg' => '20',
        'quantity_gallons' => '4', 'consumed_cost' => '22', 'consumed_by' => $this->user->id,
        'consumed_at' => now(), 'applied_at' => now(),
    ]);
    $this->remnant->update(['available_quantity_kg' => '30', 'available_quantity_gallons' => '6', 'status' => RemnantStatus::PartiallyConsumed]);

    completeTargetOrder($this)->assertSessionHasNoErrors();

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('30.0000')
        ->and(round((float) ProductionCost::where('production_order_id', $this->targetOrder->id)->value('cost'), 2))->toBe(172.0);
});

// --- Vista previa, detalle, PDF y Excel ---

it('preview costs include the estimated cost of planned remnants', function () {
    $targetDetail = ProductionOrderDetail::create([
        'production_order_id' => $this->targetOrder->id,
        'raw_material_id' => $this->material->id,
        'batch_id' => $this->batch->id,
        'planned_quantity' => 30,
        'unit_cost' => 5,
        'total_cost' => 150,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $this->targetProduct->id,
        'code' => 'VAR-TARGET',
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
        'is_active' => true,
    ]);

    $packPlan = ProductionOrderPackagingPlan::create([
        'production_order_id' => $this->targetOrder->id,
        'product_variant_id' => $variant->id,
        'planned_units' => 10,
    ]);

    // Planeado: 4 gal estimados a $5,5 = $22, sin tocar el saldo.
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();

    $data = $this->postJson(route('production-orders.preview-costs', $this->targetOrder), [
        'ingredients' => [['id' => $targetDetail->id, 'actual_quantity' => 30]],
        'packaging' => [['id' => $packPlan->id, 'actual_units' => 10]],
    ])->assertOk()->json();

    // 150 de ingredientes + 22 del saldo planeado.
    expect((float) $data['total_bulk_cost'])->toBe(172.0)
        ->and($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000');
});

it('shows each line with its product, lot, state and whether it can be removed', function () {
    $this->sourceOrder->update(['color' => 'RAL 3020']);
    $this->targetOrder->update(['status' => ProductionOrderStatus::Pending]);
    planRemnant($this, $this->targetOrder, $this->remnant, 20, 'Tanque 3')->assertSessionHasNoErrors();

    $this->get(route('production-orders.show', $this->targetOrder))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.planRemnant', true)
            ->where('order.remnant_consumptions.0.source_product_name', 'SOURCE PRODUCT RAL 3020')
            ->where('order.remnant_consumptions.0.source_order_number', $this->sourceOrder->order_number)
            ->where('order.remnant_consumptions.0.source_lot_number', $this->sourceOrder->lot_number)
            ->where('order.remnant_consumptions.0.quantity_kg', 20)
            ->where('order.remnant_consumptions.0.quantity_gallons', 4)
            // Estimado: 4 gal × $5,5.
            ->where('order.remnant_consumptions.0.consumed_cost', 22)
            ->where('order.remnant_consumptions.0.state', 'planned')
            ->where('order.remnant_consumptions.0.notes', 'Tanque 3')
            ->where('order.remnant_consumptions.0.can.remove', true)
            // La pendiente ya ofrece los saldos, con lo libre.
            ->where('order.available_remnants.0.id', $this->remnant->id)
            ->where('order.available_remnants.0.available_quantity_kg', '30.0000'));
});

it('a completed order shows its lines read-only and offers no remnants', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    completeTargetOrder($this)->assertSessionHasNoErrors();

    $this->get(route('production-orders.show', $this->targetOrder))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.planRemnant', false)
            ->where('order.remnant_consumptions.0.state', 'applied')
            ->where('order.remnant_consumptions.0.consumed_cost', 22)
            ->where('order.remnant_consumptions.0.can.remove', false)
            ->where('order.available_remnants', []));
});

it('a cancelled order keeps its planned lines as not used, without cost', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    $this->post(route('production-orders.cancel', $this->targetOrder), ['reason' => 'Ya no se va a producir'])
        ->assertSessionHasNoErrors();

    $this->get(route('production-orders.show', $this->targetOrder))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.remnant_consumptions.0.state', 'unused')
            ->where('order.remnant_consumptions.0.consumed_cost', null)
            ->where('order.remnant_consumptions.0.quantity_kg', 20)
            ->where('order.remnant_consumptions.0.can.remove', false));

    expect(RemnantConsumption::query()->count())->toBe(1)
        ->and($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000');
});

it('gives no estimated cost to a planned line whose remnant was used up by another order', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    $line = $this->remnant->consumptions()->firstOrFail();

    // Caso raro forzado: otra orden descontó el saldo completo.
    $other = otherOpenOrder($this);
    RemnantConsumption::create([
        'remnant_id' => $this->remnant->id, 'target_order_id' => $other->id, 'quantity_kg' => '50',
        'quantity_gallons' => '10', 'consumed_cost' => '55', 'consumed_by' => $this->user->id,
        'consumed_at' => now(), 'applied_at' => now(),
    ]);
    $this->remnant->update(['available_quantity_kg' => 0, 'available_quantity_gallons' => 0, 'status' => RemnantStatus::Consumed]);

    expect(app(RemnantDrawdown::class)->lineCost($line->fresh()))->toBeNull();

    $this->get(route('production-orders.show', $this->targetOrder))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.remnant_consumptions.0.state', 'planned')
            ->where('order.remnant_consumptions.0.consumed_cost', null));
});

it('gives no estimated cost to a planned line when the remnant has fewer kilos left than the line', function () {
    planRemnant($this, $this->targetOrder, $this->remnant, 20)->assertSessionHasNoErrors();
    $this->remnant->update(['available_quantity_kg' => '10', 'available_quantity_gallons' => '2', 'status' => RemnantStatus::PartiallyConsumed]);

    expect(app(RemnantDrawdown::class)->lineCost($this->remnant->consumptions()->firstOrFail()))->toBeNull();
});

it('precarga en la ficha los mismos saldos y en el mismo orden que el endpoint', function () {
    // La ficha (`BuildProductionOrderShowDataAction`) repite la consulta del endpoint. Con la misma fecha y sin
    // desempate, cada una puede devolver un orden distinto.
    // En PostgreSQL un UPDATE reubica la fila al final de la tabla, así que tocar el saldo más antiguo lo devolvería
    // último sin el desempate. En SQLite el caso no se reproduce; el CI corre los dos motores.
    $sameMoment = now()->subDay();
    ProductionRemnant::whereKey([$this->remnant->id, $this->remnant2->id])->update(['created_at' => $sameMoment]);
    ProductionRemnant::whereKey($this->remnant->id)->update(['notes' => 'reubicado al final de la tabla']);

    $fromEndpoint = collect($this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json())
        ->pluck('id')
        ->all();

    expect($fromEndpoint)->toBe([$this->remnant->id, $this->remnant2->id]);

    $this->get(route('production-orders.show', $this->targetOrder))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.available_remnants', fn ($remnants) => collect($remnants)->pluck('id')->all() === $fromEndpoint)
            ->etc());
});

it('names each available remnant with its product, color and lot', function () {
    $this->sourceOrder->update(['color' => 'RAL 3020']);

    $remnant = $this->get(route('production-orders.available-remnants', $this->targetOrder))->assertOk()->json()[0];

    expect($remnant['id'])->toBe($this->remnant->id)
        ->and($remnant['product_name'])->toBe('SOURCE PRODUCT RAL 3020')
        ->and($remnant['source_lot_number'])->toBe($this->sourceOrder->lot_number)
        ->and($remnant['source_order_number'])->toBe($this->sourceOrder->order_number);
});

it('lists the same remnants in the order detail and in the refresh endpoint', function () {
    $this->sourceOrder->update(['color' => 'RAL 3020']);

    $fromEndpoint = $this->get(route('production-orders.available-remnants', $this->targetOrder))->json();

    $this->get(route('production-orders.show', $this->targetOrder))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('order.available_remnants', $fromEndpoint));
});

it('the pdf and the excel of a pending order list its planned remnants', function () {
    $this->sourceOrder->update(['color' => 'RAL 3020']);
    $this->targetOrder->update(['status' => ProductionOrderStatus::Pending]);
    planRemnant($this, $this->targetOrder, $this->remnant, '12.5', 'Tanque 3')->assertSessionHasNoErrors();

    $payload = app(BuildProductionOrderExportDataAction::class)->execute($this->targetOrder->fresh());
    $pdfHtml = view('pdf.production-order', ['order' => $payload, 'logoBase64' => null, 'generatedAt' => '-'])->render();
    $excelHtml = view('excel.production-order', ['order' => $payload])->render();

    foreach ([$pdfHtml, $excelHtml] as $html) {
        expect($html)->toContain('SALDOS DE PT A USAR')
            ->and($html)->toContain("SALDO · {$this->sourceOrder->order_number}")
            ->and($html)->toContain('SOURCE PRODUCT RAL 3020')
            ->and($html)->toContain('Tanque 3');
    }

    expect($pdfHtml)->toContain('12,5')
        ->and($excelHtml)->toContain('12.5000');
});

it('the remnants listing shows the kilos committed in open orders', function () {
    $other = otherOpenOrder($this);
    planRemnant($this, $other, $this->remnant, 20)->assertSessionHasNoErrors();
    planRemnant($this, $this->targetOrder, $this->remnant, '5.5')->assertSessionHasNoErrors();
    // Una orden cancelada ya no compromete nada.
    $cancelled = otherOpenOrder($this);
    planRemnant($this, $cancelled, $this->remnant2, 10)->assertSessionHasNoErrors();
    $cancelled->update(['status' => ProductionOrderStatus::Cancelled]);

    $this->get(route('production.remnants.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('remnants.data', fn ($rows) => collect($rows)->pluck('committed_quantity_kg', 'id')->sortKeys()->all()
                === collect([$this->remnant->id => 25.5, $this->remnant2->id => null])->sortKeys()->all()));
});

// --- Migración ---

it('the migration marks the existing rows as already applied', function () {
    $migration = require database_path('migrations/2026_11_09_100000_add_applied_at_to_remnant_consumptions_table.php');
    $migration->down();

    DB::table('remnant_consumptions')->insert([
        'remnant_id' => $this->remnant->id, 'target_order_id' => $this->targetOrder->id, 'quantity_kg' => '5',
        'quantity_gallons' => '1', 'consumed_by' => $this->user->id, 'consumed_at' => '2026-10-01 10:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    $line = RemnantConsumption::query()->firstOrFail();

    expect($line->applied_at?->toDateTimeString())->toBe('2026-10-01 10:00:00');
});
