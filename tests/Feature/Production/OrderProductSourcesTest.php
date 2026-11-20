<?php

declare(strict_types=1);

use App\Enums\FinishedInventoryMovementReason;
use App\Enums\FormulaLineCostingBasis;
use App\Enums\InventoryMovementType;
use App\Enums\ProductionOrderProductLineOrigin;
use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantMovementType;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\FinishedInventoryMovement;
use App\Models\FinishedProductBatch;
use App\Models\FinishedProductBatchStock;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductionOrderProductLine;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RemnantConsumption;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinishedInventory\FinishedInventoryMovementService;
use App\Services\Production\ProductionOrderCompletionLines;
use App\Services\Production\ProductionOrderCostSummary;
use App\Services\Production\ProductLinePlanner;
use App\Services\Production\RemnantConsumptionLineBackfill;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/*
 * «Productos a usar» en el servidor: líneas de producto de la OP, fuentes (saldos y lotes de PT), lo comprometido de un
 * lote, el consumo al completar con lo que quedó pesado en un envase abierto, y su costo.
 *
 * El caso de la hoja de planta: 20 cuñetes 4G de 23,4 kg (4 gal × 5,85 kg/gal) del lote 1682, a 226.908,2885 cada uno
 * (costo de traslado, con CIF); granel de su OP 41.827,8888 por galón con CIF 15 % = 48.102,0721. Se usaron 462,3 kg y
 * quedaron 5,7 kg pesados en un cuñete: vuelven al saldo de la OP de origen por 5,7 × 48.102,0721 ÷ 5,85 = 46.868,6856,
 * y la OP paga 20 × 226.908,2885 − 46.868,6856 = 4.491.297,0844.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'job_title' => 'Analista de Calidad',
        'signature_path' => 'signatures/test.png',
    ]);
    $this->user->assignRole(SystemRole::Admin->value);
    $this->actingAs($this->user);

    $category = ProductCategory::create(['name' => 'Esmaltes']);
    $this->gallon = UnitOfMeasure::create(['code' => 'gal', 'name' => 'Galón', 'symbol' => 'gal']);
    $this->kilo = UnitOfMeasure::create(['code' => 'kg', 'name' => 'Kilogramo', 'symbol' => 'kg', 'to_kg_conversion' => 1]);

    $this->warehouse = Warehouse::create(['name' => 'Planta', 'city' => 'Cali', 'type' => 'factory', 'is_active' => true]);
    $this->otherWarehouse = Warehouse::create(['name' => 'Almacén Norte', 'city' => 'Cali', 'type' => 'factory', 'is_active' => true]);

    $this->white = Product::create([
        'name' => 'ESMALTE BLANCO',
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->gallon->id,
        'current_cost' => 41827.8888,
        'cif_percentage' => 15,
        'current_price' => 60000,
        'price_threshold' => 5,
    ]);
    $this->ral = Product::create([
        'name' => 'RAL 7016',
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->gallon->id,
        'current_cost' => 50000,
        'cif_percentage' => 15,
        'current_price' => 70000,
        'price_threshold' => 5,
    ]);
    $this->bucket = ProductVariant::create([
        'product_id' => $this->white->id,
        'code' => 'BL-4G',
        'unit_of_measure_id' => $this->gallon->id,
        'presentation_value' => 4,
        'presentation_label' => 'Cuñete 4G',
        'is_active' => true,
    ]);
    $this->ralGallon = ProductVariant::create([
        'product_id' => $this->ral->id,
        'code' => 'RAL-1G',
        'unit_of_measure_id' => $this->gallon->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
        'is_active' => true,
    ]);

    $this->material = RawMaterial::create(['code' => 'S-7', 'unit_of_measure_id' => $this->kilo->id, 'current_price' => 5]);
    InventoryBatch::create([
        'raw_material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'initial_quantity' => 1000,
        'remaining_quantity' => 1000,
        'unit_price' => 5,
        'entry_date' => now(),
    ]);

    $whiteFormula = Formula::create(['product_id' => $this->white->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->user->id]);
    FormulaDetail::create(['formula_id' => $whiteFormula->id, 'raw_material_id' => $this->material->id, 'quantity' => 1, 'unit_of_measure_id' => $this->kilo->id]);

    // RAL 7016 lleva 3 kg de blanco por galón (paso 2) y la MP S-7 (paso 1).
    $this->ralFormula = Formula::create(['product_id' => $this->ral->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->user->id]);
    FormulaDetail::create(['formula_id' => $this->ralFormula->id, 'raw_material_id' => $this->material->id, 'quantity' => 0.1, 'unit_of_measure_id' => $this->kilo->id, 'step_order' => 1]);
    FormulaDetail::create([
        'formula_id' => $this->ralFormula->id,
        'product_id' => $this->white->id,
        'costing_basis' => FormulaLineCostingBasis::Bulk,
        'quantity' => 3,
        'unit_of_measure_id' => $this->kilo->id,
        'step_order' => 2,
    ]);

    // La OP del blanco (lote 1682), completada con peso por galón 5,85, CIF 15 % y granel 41.827,8888 por galón.
    $this->origin = ProductionOrder::create([
        'order_number' => 'OP-2026-0001',
        'lot_number' => 1682,
        'product_id' => $this->white->id,
        'formula_id' => $whiteFormula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 100,
        'status' => ProductionOrderStatus::Completed->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
        'density_kg_per_gallon' => '5.85',
        'cif_percentage' => '15',
    ]);
    ProductionCost::create([
        'product_id' => $this->white->id,
        'formula_id' => $whiteFormula->id,
        'production_order_id' => $this->origin->id,
        'cost' => '4182788.88',
        'unit_cost' => '41827.8888',
        'calculated_at' => now(),
    ]);

    $this->lot = opsBatch($this, $this->origin, units: 25, transferUnitCost: '226908.2885');
});

/** Un lote de cuñetes de blanco de `$order`, con su entrada de producción en la bodega (stock y caché al día). */
function opsBatch(object $test, ProductionOrder $order, int $units, ?string $transferUnitCost, ?Warehouse $warehouse = null, string $entryDate = '2026-11-01', string $entryCost = '197311.5552', ?ProductVariant $variant = null): FinishedProductBatch
{
    $batch = FinishedProductBatch::create([
        'product_id' => $order->product_id,
        'product_variant_id' => ($variant ?? $test->bucket)->id,
        'production_order_id' => $order->id,
        'initial_quantity' => $units,
        'transfer_unit_cost' => $transferUnitCost,
        'transfer_cost_estimated' => false,
        'entry_date' => $entryDate,
    ]);

    app(FinishedInventoryMovementService::class)->registerEntry(
        batchId: $batch->id,
        warehouseId: ($warehouse ?? $test->warehouse)->id,
        quantity: (string) $units,
        reason: FinishedInventoryMovementReason::Production,
        userId: $test->user->id,
        productionOrderId: $order->id,
        costPrice: $entryCost,
        movementDate: $entryDate,
    );

    return $batch;
}

/** Otra OP completada del blanco, con su lote. */
function opsOtherOrigin(object $test, int $lotNumber, string $entryDate, int $units, ?Warehouse $warehouse = null): FinishedProductBatch
{
    $order = $test->origin->replicate()->fill([
        'order_number' => 'OP-2026-'.$lotNumber,
        'lot_number' => $lotNumber,
    ]);
    $order->save();

    return opsBatch($test, $order, units: $units, transferUnitCost: '226908.2885', warehouse: $warehouse, entryDate: $entryDate);
}

/** El saldo de la OP de origen, a precio de granel (48.102,0721 por galón). */
function opsOriginRemnant(object $test, string $kilos, ?Warehouse $warehouse = null, RemnantStatus $status = RemnantStatus::Available): ProductionRemnant
{
    $gallons = bcdiv($kilos, '5.85', 4);

    return ProductionRemnant::create([
        'source_order_id' => $test->origin->id,
        'product_id' => $test->white->id,
        'warehouse_id' => ($warehouse ?? $test->warehouse)->id,
        'original_quantity_gallons' => $gallons,
        'original_quantity_kg' => $kilos,
        'available_quantity_gallons' => $gallons,
        'available_quantity_kg' => $kilos,
        'density_kg_per_gallon' => '5.85',
        'cost_per_gallon' => '48102.0721',
        'status' => $status,
        'created_by' => $test->user->id,
    ]);
}

/**
 * La OP de RAL 7016 (154,1 gal → 462,3 kg de blanco), en curso, con su línea de MP, una presentación y sus líneas de
 * producto con fuentes preseleccionadas por la pieza de creación. La OP se arma a mano: crear una OP con una fórmula que
 * lleva productos sigue bloqueado hasta que la pantalla las muestre.
 */
function opsRalOrder(object $test, string $quantity = '154.1', ProductionOrderStatus $status = ProductionOrderStatus::InProgress, ?Warehouse $warehouse = null): ProductionOrder
{
    $order = ProductionOrder::create([
        'order_number' => 'OP-RAL-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $test->ral->id,
        'formula_id' => $test->ralFormula->id,
        'warehouse_id' => ($warehouse ?? $test->warehouse)->id,
        'quantity' => $quantity,
        'status' => $status->value,
        'planned_date' => now(),
        'created_by' => $test->user->id,
    ]);

    ProductionOrderDetail::create([
        'production_order_id' => $order->id,
        'raw_material_id' => $test->material->id,
        'step_order' => 1,
        'planned_quantity' => 10,
        'unit_cost' => 5,
        'total_cost' => 50,
    ]);
    ProductionOrderPackagingPlan::create([
        'production_order_id' => $order->id,
        'product_variant_id' => $test->ralGallon->id,
        'planned_units' => 10,
    ]);

    app(ProductLinePlanner::class)->planFromFormula($order, $test->ralFormula->fresh(), $test->user->id);

    return $order->fresh();
}

/**
 * Completa (o envía a revisión) la OP con lo que mostraría la pantalla: el ingrediente tal cual lo planeado, 10 galones
 * envasados, los saldos y los lotes de la orden. `$batchRows` lleva por lote los kilos usados y lo que quedó.
 *
 * @param  list<array<string, mixed>>|null  $batchRows  null: no envía la lista de lotes
 */
function opsComplete(object $test, ProductionOrder $order, ?array $batchRows, string $route = 'production-orders.complete'): TestResponse
{
    $detail = ProductionOrderDetail::query()->where('production_order_id', $order->id)->firstOrFail();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->firstOrFail();

    return $test->post(route($route, $order), array_filter([
        'density_kg_per_gallon' => 5,
        'quality_responsible_user_id' => $test->user->id,
        'line_adjustment_ids' => [],
        'remnant_consumption_ids' => RemnantConsumption::query()->where('target_order_id', $order->id)->pluck('id')->all(),
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 10]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
        'batch_consumptions' => $batchRows,
    ], fn ($value) => $value !== null));
}

/** La fila de la pantalla de cada lote de la OP: kilos usados y, si se da, lo que quedó pesado. */
function opsRows(ProductionOrder $order, string $usedKg = '462.3', ?string $leftoverKg = null): array
{
    return ProductionOrderBatchConsumption::query()
        ->where('production_order_id', $order->id)
        ->orderBy('id')
        ->get()
        ->map(fn (ProductionOrderBatchConsumption $source): array => array_filter([
            'id' => $source->id,
            'used_kg' => $usedKg,
            'has_leftover' => $leftoverKg !== null,
            'leftover_kg' => $leftoverKg,
        ], fn ($value) => $value !== null))
        ->all();
}

// --- Migración de datos ---

it('gives each existing remnant consumption an added line of its product in its order', function () {
    // Las órdenes antes que el saldo: la preselección no lo toma.
    $order = opsRalOrder($this, '1');
    ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->delete();
    ProductionOrderProductLine::query()->where('production_order_id', $order->id)->delete();
    $other = opsRalOrder($this, '1');
    // Una línea que la OP ya tiene se reutiliza.
    $existingLine = ProductionOrderProductLine::query()->where('production_order_id', $other->id)->firstOrFail();
    $remnant = opsOriginRemnant($this, '50');

    $make = fn (ProductionOrder $target): RemnantConsumption => RemnantConsumption::create([
        'remnant_id' => $remnant->id,
        'target_order_id' => $target->id,
        'quantity_kg' => '1',
        'quantity_gallons' => '0.1709',
        'consumed_by' => $this->user->id,
        'consumed_at' => now(),
    ]);
    $first = $make($order);
    $second = $make($order);
    $third = $make($other);
    $orphan = RemnantConsumption::create([
        'remnant_id' => $remnant->id,
        'target_order_id' => null,
        'quantity_kg' => '1',
        'quantity_gallons' => '0.1709',
        'consumed_by' => $this->user->id,
        'consumed_at' => now(),
    ]);
    $activitiesBefore = Activity::query()->count();

    expect(app(RemnantConsumptionLineBackfill::class)->run())->toBe(3);

    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    expect($line->origin)->toBe(ProductionOrderProductLineOrigin::Added)
        ->and((int) $line->product_id)->toBe($this->white->id)
        ->and($line->planned_kg)->toBeNull()
        ->and((int) $first->fresh()->production_order_product_line_id)->toBe($line->id)
        ->and((int) $second->fresh()->production_order_product_line_id)->toBe($line->id)
        ->and((int) $third->fresh()->production_order_product_line_id)->toBe($existingLine->id)
        ->and($orphan->fresh()->production_order_product_line_id)->toBeNull()
        // Sin auditoría por fila, y correrlo otra vez no cambia nada.
        ->and(Activity::query()->count())->toBe($activitiesBefore)
        ->and(app(RemnantConsumptionLineBackfill::class)->run())->toBe(0);
});

it('runs the backfill from the migration', function () {
    $migration = file_get_contents(database_path('migrations/2026_11_20_200000_add_order_product_sources.php'));

    expect($migration)->toContain('RemnantConsumptionLineBackfill::class)->run()');
});

// --- Crear la OP: líneas y preselección ---

it('copies each product line of the formula with its kilos and preselects remnants first, then lots by FIFO in whole units', function () {
    // Saldo de 30 kg con 10 planeados por otra OP abierta: libres 20.
    $remnant = opsOriginRemnant($this, '30');
    $busy = opsRalOrder($this, '1', ProductionOrderStatus::Pending);
    RemnantConsumption::query()->where('target_order_id', $busy->id)->update(['quantity_kg' => '10']);
    ProductionOrderBatchConsumption::query()->where('production_order_id', $busy->id)->delete();
    // Un lote más viejo, de 10 cuñetes: va primero.
    $older = opsOtherOrigin($this, 1500, '2026-10-01', 10);

    $order = opsRalOrder($this);

    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    $remnantSource = RemnantConsumption::query()->where('target_order_id', $order->id)->sole();
    $lots = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->orderBy('id')->get();

    // 3 kg/gal × 154,1 gal = 462,3 kg: 20 del saldo; quedan 442,3 → 10 cuñetes del lote viejo (234 kg) y techo(208,3 ÷
    // 23,4) = 9 del nuevo.
    expect($line->origin)->toBe(ProductionOrderProductLineOrigin::Formula)
        ->and($line->planned_kg)->toBe('462.3000')
        ->and($line->step_order)->toBe(2)
        ->and($remnantSource->remnant_id)->toBe($remnant->id)
        ->and($remnantSource->quantity_kg)->toBe('20.0000')
        ->and((int) $remnantSource->production_order_product_line_id)->toBe($line->id)
        ->and($lots->pluck('finished_product_batch_id')->all())->toBe([$older->id, $this->lot->id])
        ->and($lots->pluck('units')->all())->toBe([10, 9])
        ->and($lots->every(fn ($source) => (int) $source->warehouse_id === $this->warehouse->id))->toBeTrue()
        // No sale nada del inventario al crear.
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(25);
});

it('creates the order anyway when the sources do not cover the line, and only uses the order warehouse', function () {
    // Saldo y lote en otra bodega: no cuentan.
    opsOriginRemnant($this, '100', $this->otherWarehouse);
    opsOtherOrigin($this, 1501, '2026-09-01', 30, $this->otherWarehouse);

    // 3 kg × 300 gal = 900 kg; el único lote de la bodega tiene 25 cuñetes (585 kg).
    $order = opsRalOrder($this, '300');

    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    $lots = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->get();

    expect($line->planned_kg)->toBe('900.0000')
        ->and(RemnantConsumption::query()->where('target_order_id', $order->id)->exists())->toBeFalse()
        ->and($lots)->toHaveCount(1)
        ->and($lots->first()->finished_product_batch_id)->toBe($this->lot->id)
        ->and($lots->first()->units)->toBe(25);
});

it('plans the kilos of a product line written in grams', function () {
    $gram = UnitOfMeasure::create(['code' => 'g', 'name' => 'Gramo', 'symbol' => 'g', 'to_kg_conversion' => 0.001]);
    $detail = FormulaDetail::query()->whereNotNull('product_id')->firstOrFail();
    $detail->update(['quantity' => 3000, 'unit_of_measure_id' => $gram->id]);

    expect(app(ProductLinePlanner::class)->plannedKg($detail->fresh(['unitOfMeasure', 'product']), '154.1'))->toBe('462.3000');
});

it('keeps rejecting an order whose formula carries products', function () {
    $this->post(route('production-orders.store'), [
        'product_id' => $this->ral->id,
        'formula_id' => $this->ralFormula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 10,
        'planned_date' => plantToday(),
        'packaging' => [['product_variant_id' => $this->ralGallon->id, 'planned_units' => 10]],
    ])->assertSessionHasErrors(['formula_id']);

    expect(ProductionOrderProductLine::query()->exists())->toBeFalse();
});

// --- Agregar, cambiar y quitar fuentes ---

it('adds a lot of a product the order does not carry as an added line, and removing its last source removes the line', function () {
    $order = opsRalOrder($this, '1');
    ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->delete();
    $grayProduct = $this->ral->replicate()->fill(['name' => 'GRIS CLARO']);
    $grayProduct->save();
    $grayOrder = $this->origin->replicate()->fill(['order_number' => 'OP-2026-0300', 'lot_number' => 300, 'product_id' => $grayProduct->id]);
    $grayOrder->save();
    $grayVariant = $this->bucket->replicate()->fill(['product_id' => $grayProduct->id, 'code' => 'GR-4G']);
    $grayVariant->save();
    $grayBatch = opsBatch($this, $grayOrder, units: 5, transferUnitCost: '100000', variant: $grayVariant);

    $this->post(route('production-orders.batch-consumptions.store', $order), [
        'finished_product_batch_id' => $grayBatch->id,
        'units' => 2,
    ])->assertSessionHasNoErrors()->assertInertiaFlash('success', 'Lote agregado a la orden.');

    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->where('product_id', $grayProduct->id)->sole();
    $source = ProductionOrderBatchConsumption::query()->where('production_order_product_line_id', $line->id)->sole();

    expect($line->origin)->toBe(ProductionOrderProductLineOrigin::Added)
        ->and($line->planned_kg)->toBeNull()
        ->and($source->units)->toBe(2)
        ->and((int) $source->created_by)->toBe($this->user->id);

    $this->delete(route('production-orders.batch-consumptions.destroy', [$order, $source]))->assertSessionHasNoErrors();

    expect(ProductionOrderProductLine::query()->whereKey($line->id)->exists())->toBeFalse()
        // La línea de la fórmula se queda aunque no tenga fuentes.
        ->and(ProductionOrderProductLine::query()->where('production_order_id', $order->id)->where('product_id', $this->white->id)->exists())->toBeTrue();
});

it('attaches a planned remnant to the product line of its product', function () {
    $remnant = opsOriginRemnant($this, '50');
    $order = opsRalOrder($this, '1');
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    RemnantConsumption::query()->where('target_order_id', $order->id)->delete();

    $this->post(route('production-orders.consume-remnant', $order), ['remnant_id' => $remnant->id, 'quantity_kg' => 5])
        ->assertSessionHasNoErrors();

    $source = RemnantConsumption::query()->where('target_order_id', $order->id)->sole();
    expect((int) $source->production_order_product_line_id)->toBe($line->id);

    // Quitar el saldo no quita la línea de la fórmula.
    $this->delete(route('production-orders.remnant-consumptions.destroy', [$order, $source]))->assertSessionHasNoErrors();
    expect(ProductionOrderProductLine::query()->whereKey($line->id)->exists())->toBeTrue();
});

it('changes the units of a lot without passing what is free, and rejects a lot from another warehouse', function () {
    $order = opsRalOrder($this);
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();

    // 25 en bodega: la fuente puede subir a 25 (sus propias 20 no compiten), no a 26.
    $this->patch(route('production-orders.batch-consumptions.update', [$order, $source]), ['units' => 26])
        ->assertSessionHasErrors(['units']);
    $this->patch(route('production-orders.batch-consumptions.update', [$order, $source]), ['units' => 25])
        ->assertSessionHasNoErrors();
    expect($source->fresh()->units)->toBe(25);

    $faraway = opsOtherOrigin($this, 1502, '2026-09-01', 10, $this->otherWarehouse);
    $this->post(route('production-orders.batch-consumptions.store', $order), ['finished_product_batch_id' => $faraway->id, 'units' => 1])
        ->assertSessionHasErrors(['finished_product_batch_id']);
});

it('does not touch the sources of a completed order', function () {
    $order = opsRalOrder($this);
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $order->update(['status' => ProductionOrderStatus::Completed]);

    $this->post(route('production-orders.batch-consumptions.store', $order), ['finished_product_batch_id' => $this->lot->id, 'units' => 1])->assertForbidden();
    $this->patch(route('production-orders.batch-consumptions.update', [$order, $source]), ['units' => 1])->assertForbidden();
    $this->delete(route('production-orders.batch-consumptions.destroy', [$order, $source]))->assertForbidden();
});

// --- Comprometido ---

it('does not let two orders take the same units, nor a sale, adjustment or transfer, and frees them when the order is cancelled', function () {
    $first = opsRalOrder($this); // 20 de 25 comprometidos
    $second = opsRalOrder($this, '1');
    ProductionOrderBatchConsumption::query()->where('production_order_id', $second->id)->delete();

    $this->post(route('production-orders.batch-consumptions.store', $second), ['finished_product_batch_id' => $this->lot->id, 'units' => 6])
        ->assertSessionHasErrors(['units' => __('El lote :lot tiene :stock unidades en la bodega y :committed están comprometidas en órdenes abiertas (:orders): quedan :free libres.', [
            'lot' => 1682, 'stock' => '25', 'committed' => '20', 'orders' => $first->order_number.' (20)', 'free' => '5',
        ])]);

    $exit = fn (string $reason, int $quantity, array $extra = []): TestResponse => $this->post(route('finished-inventory-movements.store'), [
        'type' => 'exit',
        'reason' => $reason,
        'finished_product_batch_id' => $this->lot->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => $quantity,
        'movement_date' => plantToday(),
        ...$extra,
    ]);

    $exit('sale', 6)->assertSessionHasErrors(['quantity']);
    $exit('adjustment', 6)->assertSessionHasErrors(['quantity']);
    $exit('transfer', 6, ['destination_warehouse_id' => $this->otherWarehouse->id])->assertSessionHasErrors(['quantity']);
    expect(session('errors')->first('quantity'))->toContain($first->order_number);

    $exit('sale', 5)->assertSessionHasNoErrors();

    // Cancelada, ya no compromete nada.
    $first->update(['status' => ProductionOrderStatus::Cancelled]);
    $exit('sample', 20)->assertSessionHasNoErrors();
    expect(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(0);
});

// --- Completar ---

it('completes taking out the units of the source, without remnant when nothing was left', function () {
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order))->assertSessionHasNoErrors();

    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $exit = FinishedInventoryMovement::query()->where('finished_product_batch_id', $this->lot->id)->where('type', InventoryMovementType::Exit)->sole();

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Completed)
        ->and($source->units)->toBe(20)
        ->and($source->used_kg)->toBe('462.3000')
        ->and($source->leftover_kg)->toBeNull()
        ->and($source->kg_per_unit)->toBe('23.4000')
        ->and($source->consumed_cost)->toBe('4538165.7700')
        ->and($source->applied_at)->not->toBeNull()
        ->and($exit->reason)->toBe(FinishedInventoryMovementReason::Transformation)
        ->and((int) $exit->production_order_id)->toBe($order->id)
        ->and($exit->quantity)->toBe(20)
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(5)
        ->and(ProductionRemnant::query()->where('source_order_id', $this->origin->id)->exists())->toBeFalse()
        // Granel = 50 de la MP + los 20 cuñetes.
        ->and(ProductionCost::query()->where('production_order_id', $order->id)->value('cost'))->toBe('4538215.7700');
});

it('returns what was left in the opened bucket to the origin remnant at bulk price: the sheet case', function () {
    $originRemnant = opsOriginRemnant($this, '10');
    $order = opsRalOrder($this);
    // La preselección tomó el saldo primero (10 kg): se quita para tener solo los 20 cuñetes de la hoja.
    RemnantConsumption::query()->where('target_order_id', $order->id)->delete();
    ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->update(['units' => 20]);

    opsComplete($this, $order, opsRows($order, '462.3', '5.7'))->assertSessionHasNoErrors();

    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $originRemnant->refresh();
    $movement = ProductionRemnantMovement::query()->where('production_remnant_id', $originRemnant->id)->sole();

    expect($source->leftover_kg)->toBe('5.7000')
        ->and($source->consumed_cost)->toBe('4491297.0844')
        ->and($originRemnant->available_quantity_kg)->toBe('15.7000')
        // Los galones salen de los kilos que quedan: 15,7 ÷ 5,85 = 2,68376 → 2,6838.
        ->and($originRemnant->available_quantity_gallons)->toBe('2.6838')
        ->and($originRemnant->original_quantity_kg)->toBe('10.0000')
        ->and($originRemnant->cost_per_gallon)->toBe('48102.0721')
        ->and($movement->type)->toBe(RemnantMovementType::OpenedUnit)
        ->and($movement->quantity_kg)->toBe('5.7000')
        ->and($movement->balance_after_kg)->toBe('15.7000')
        ->and((int) $movement->production_order_id)->toBe($order->id)
        ->and((int) $movement->production_order_batch_consumption_id)->toBe($source->id)
        // Salen los 20: el cuñete abierto también.
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(5)
        // No suma al rendimiento ni al sobrante de la OP que lo abrió.
        ->and(ProductionRemnant::query()->where('source_order_id', $order->id)->exists())->toBeFalse()
        ->and($order->fresh()->actual_quantity)->toBe('10.0000');

    // Se conserva el costo: lo que paga la OP más lo que vuelve al saldo es lo que salió del lote.
    $returnedValue = bcdiv(bcmul('5.7', '48102.0721', 10), '5.85', 4);
    expect($returnedValue)->toBe('46868.6856')
        ->and(bcadd($source->consumed_cost, $returnedValue, 4))->toBe('4538165.7700');
});

it('creates the origin remnant when its order has none, in the warehouse of the consuming order', function () {
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '462.3', '5.7'))->assertSessionHasNoErrors();

    $remnant = ProductionRemnant::query()->where('source_order_id', $this->origin->id)->sole();
    $movement = $remnant->movements()->sole();

    expect($remnant->available_quantity_kg)->toBe('5.7000')
        ->and($remnant->original_quantity_kg)->toBe('5.7000')
        ->and($remnant->status)->toBe(RemnantStatus::Available)
        ->and((int) $remnant->warehouse_id)->toBe($this->warehouse->id)
        ->and($remnant->density_kg_per_gallon)->toBe('5.8500')
        // Granel 41.827,8888 con el CIF 15 % de la OP de origen.
        ->and($remnant->cost_per_gallon)->toBe('48102.0721')
        ->and($movement->type)->toBe(RemnantMovementType::OpenedUnit)
        ->and(ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->value('consumed_cost'))->toBe('4491297.0844');
});

it('brings a consumed or written-off origin remnant back to life', function (bool $wasUsed, RemnantStatus $expected) {
    $originRemnant = opsOriginRemnant($this, '0', status: RemnantStatus::Consumed);

    if ($wasUsed) {
        $previous = opsRalOrder($this, '1', ProductionOrderStatus::Completed);
        RemnantConsumption::create([
            'remnant_id' => $originRemnant->id,
            'target_order_id' => $previous->id,
            'quantity_kg' => '3',
            'quantity_gallons' => '0.5128',
            'consumed_cost' => '24666.4',
            'consumed_by' => $this->user->id,
            'consumed_at' => now(),
            'applied_at' => now(),
        ]);
        ProductionOrderBatchConsumption::query()->where('production_order_id', $previous->id)->delete();
    }

    $order = opsRalOrder($this);
    opsComplete($this, $order, opsRows($order, '462.3', '5.7'))->assertSessionHasNoErrors();

    expect($originRemnant->fresh()->available_quantity_kg)->toBe('5.7000')
        ->and($originRemnant->fresh()->status)->toBe($expected);
})->with([
    'consumido por otra OP' => [true, RemnantStatus::PartiallyConsumed],
    'dado de baja' => [false, RemnantStatus::Available],
]);

it('does not complete when the origin remnant is in another warehouse', function () {
    $originRemnant = opsOriginRemnant($this, '10', $this->otherWarehouse);
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '462.3', '5.7'))
        ->assertSessionHasErrors(['batch_consumptions' => __('Lo que quedó del lote :lot vuelve al saldo de :origin, y ese saldo está en otra bodega (:warehouse): no se pueden juntar, así que la orden no se puede completar.', [
            'lot' => 1682, 'origin' => 'OP-2026-0001', 'warehouse' => 'Almacén Norte',
        ])]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and($originRemnant->fresh()->available_quantity_kg)->toBe('10.0000')
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(25);
});

it('rejects a leftover heavier than one unit, or a leftover without its weight', function () {
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '462.3', '23.5'))
        ->assertSessionHasErrors(['batch_consumptions.0.leftover_kg']);

    $rows = opsRows($order);
    $rows[0]['has_leftover'] = true;
    opsComplete($this, $order, $rows)->assertSessionHasErrors(['batch_consumptions.0.leftover_kg']);

    // Los kilos usados son obligatorios.
    unset($rows[0]['used_kg'], $rows[0]['has_leftover']);
    opsComplete($this, $order, $rows)->assertSessionHasErrors(['batch_consumptions.0.used_kg']);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);
});

it('does not submit or complete while a formula line has no source', function (string $route) {
    $order = opsRalOrder($this);
    ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->delete();

    opsComplete($this, $order, [], $route)
        ->assertSessionHasErrors(['product_lines' => __('Falta de dónde sale :products: agrega un saldo o un lote a cada producto de la fórmula.', ['products' => 'ESMALTE BLANCO'])]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);
})->with(['completar' => 'production-orders.complete', 'enviar a revisión' => 'production-orders.submit-for-review']);

it('saves the used kilos and the leftover when submitting for review, and the reviewer completes with them', function () {
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '462.3', '5.7'), 'production-orders.submit-for-review')->assertSessionHasNoErrors();

    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::PendingReview)
        ->and($source->used_kg)->toBe('462.3000')
        ->and($source->leftover_kg)->toBe('5.7000')
        ->and($source->applied_at)->toBeNull()
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(25);

    // Quien aprueba corrige: no quedó nada.
    opsComplete($this, $order, opsRows($order))->assertSessionHasNoErrors();

    expect($source->fresh()->leftover_kg)->toBeNull()
        ->and($source->fresh()->consumed_cost)->toBe('4538165.7700');
});

it('warns that the order changed when a lot was added after the screen loaded', function () {
    $order = opsRalOrder($this);

    // La pantalla no mandó el lote que la orden tiene.
    opsComplete($this, $order, [])
        ->assertSessionHasErrors(['batch_consumptions' => __(ProductionOrderCompletionLines::ORDER_CHANGED_MESSAGE)]);
    opsComplete($this, $order, null)
        ->assertSessionHasErrors(['batch_consumptions' => __(ProductionOrderCompletionLines::ORDER_CHANGED_MESSAGE)]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);
});

it('estimates and stores the transfer cost of a lot that has none when consuming it', function () {
    $this->lot->update(['transfer_unit_cost' => null]);
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order))->assertSessionHasNoErrors();

    // Entrada de 197.311,5552 con el CIF 15 % de hoy del producto = 226.908,2885 (redondeado), marcado estimado.
    expect($this->lot->fresh()->transfer_unit_cost)->toBe('226908.2885')
        ->and($this->lot->fresh()->transfer_cost_estimated)->toBeTrue()
        ->and(ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->value('consumed_cost'))->toBe('4538165.7700');
});

// --- Costo ---

it('adds the lots to the bulk cost in the summary and the preview, and keeps the line adjustments apart', function () {
    $order = opsRalOrder($this);
    $detail = ProductionOrderDetail::query()->where('production_order_id', $order->id)->firstOrFail();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->firstOrFail();

    $preview = $this->postJson(route('production-orders.preview-costs', $order), [
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 10]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
    ])->assertOk()->json('summary.bulk');

    expect($preview)->toBe([
        'total' => '4538215.7700',
        'raw_materials' => '50.0000',
        'line_adjustments' => '0.0000',
        'remnants_used' => '0.0000',
        'products_used' => '4538165.7700',
    ]);

    opsComplete($this, $order, opsRows($order, '462.3', '5.7'))->assertSessionHasNoErrors();

    $summary = app(ProductionOrderCostSummary::class)->forOrder($order->fresh());

    expect($summary['bulk'])->toBe([
        'total' => '4491347.0844',
        'raw_materials' => '50.0000',
        'line_adjustments' => '0.0000',
        'remnants_used' => '0.0000',
        'products_used' => '4491297.0844',
    ]);
});

// --- Inventario ---

it('does not return a transformation of an order, but still returns an old manual one', function () {
    $order = opsRalOrder($this);
    opsComplete($this, $order, opsRows($order))->assertSessionHasNoErrors();

    $returnEntry = fn (int $quantity): TestResponse => $this->post(route('finished-inventory-movements.store'), [
        'type' => 'entry',
        'reason' => 'return',
        'finished_product_batch_id' => $this->lot->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => $quantity,
        'movement_date' => plantToday(),
    ]);

    $returnEntry(1)->assertSessionHasErrors(['quantity']);

    // Una «Transformación» registrada a mano antes, sin orden: sigue siendo devolvible.
    app(FinishedInventoryMovementService::class)->registerExit(
        batchId: $this->lot->id,
        warehouseId: $this->warehouse->id,
        quantity: '2',
        reason: FinishedInventoryMovementReason::Transformation,
        userId: $this->user->id,
    );

    $returnEntry(2)->assertSessionHasNoErrors();
    $returnEntry(1)->assertSessionHasErrors(['quantity']);
});

// --- Auditoría ---

it('audits the product lines and the lot sources in the order log', function () {
    $order = opsRalOrder($this);
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();

    $lineLog = Activity::query()->where('subject_type', ProductionOrderProductLine::class)->where('subject_id', $line->id)->sole();
    $sourceLog = Activity::query()->where('subject_type', ProductionOrderBatchConsumption::class)->where('subject_id', $source->id)->sole();

    expect($lineLog->log_name)->toBe('ordenes_produccion')
        ->and($lineLog->description)->toContain($order->order_number)
        ->and($lineLog->description)->toContain('ESMALTE BLANCO')
        ->and($sourceLog->log_name)->toBe('ordenes_produccion')
        ->and($sourceLog->description)->toContain('lote 1682')
        ->and($sourceLog->properties['attributes']['units'] ?? null)->toBe(20);
});

it('keeps the lock order: order, then remnants, then lots', function () {
    $originRemnant = opsOriginRemnant($this, '10');
    $order = opsRalOrder($this);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $queries[] = $query->sql;
        }
    });

    opsComplete($this, $order, opsRows($order, '442.3', '5.7'))->assertSessionHasNoErrors();

    $tables = collect($queries)
        ->map(fn (string $sql): ?string => collect(['production_orders', 'production_remnants', 'finished_product_batches"', 'finished_product_batch_stocks'])
            ->first(fn (string $table): bool => str_contains($sql, 'from "'.rtrim($table, '"').'"')))
        ->filter()
        ->values();

    // En SQLite no hay FOR UPDATE: solo se mide en PostgreSQL.
    if (DB::connection()->getDriverName() !== 'pgsql') {
        expect($tables)->toBeEmpty();

        return;
    }

    $firstIndex = fn (string $table): int => $tables->search($table);
    expect($firstIndex('production_orders'))->toBeLessThan($firstIndex('production_remnants'))
        ->and($firstIndex('production_remnants'))->toBeLessThan($firstIndex('finished_product_batches"'))
        ->and($originRemnant->fresh()->available_quantity_kg)->not->toBe('10.0000');
});
