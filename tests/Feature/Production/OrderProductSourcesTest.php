<?php

declare(strict_types=1);

use App\Actions\Production\BuildProductionOrderExportDataAction;
use App\Enums\FinishedInventoryMovementReason;
use App\Enums\FormulaLineCostingBasis;
use App\Enums\InventoryMovementType;
use App\Enums\ProductionOrderProductLineOrigin;
use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantMovementType;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\Alert;
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
use App\Models\ProductionOrderConsumptionReason;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductionOrderProductLine;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RemnantConsumption;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AvailableBatchesService;
use App\Services\DecimalCalculator;
use App\Services\FinishedInventory\FinishedInventoryMovementService;
use App\Services\Production\BatchSourceDrawdown;
use App\Services\Production\ProductionOrderCompletionLines;
use App\Services\Production\ProductionOrderCostSummary;
use App\Services\Production\ProductLinePlanner;
use App\Services\Production\RemnantConsumptionLineBackfill;
use App\Support\NumberFormat;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
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
 * La OP de RAL 7016 (154,1 gal → 462,3 kg de blanco), en curso, con su línea de MP, una presentación y su línea de
 * producto (la pieza de creación, la misma que llama crear la OP: sin fuentes). Como lo haría una persona en la orden, la
 * línea sale del lote 1682: las unidades que cubren lo planeado, sin pasar de las libres (`$lotUnits` las fija; 0, sin
 * lote). Se arma a mano para elegir el estado y la bodega sin pasar por la pantalla de crear.
 */
function opsRalOrder(object $test, string $quantity = '154.1', ProductionOrderStatus $status = ProductionOrderStatus::InProgress, ?Warehouse $warehouse = null, ?int $lotUnits = null): ProductionOrder
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

    $line = app(ProductLinePlanner::class)->planFromFormula($order, $test->ralFormula->fresh())->first();
    $warehouseId = ($warehouse ?? $test->warehouse)->id;
    $free = (int) app(AvailableBatchesService::class)->freeUnits($test->lot->id, $warehouseId);
    $units = $lotUnits ?? min((int) ceil((float) $line->planned_kg / 23.4), $free);

    if ($units > 0) {
        ProductionOrderBatchConsumption::create([
            'production_order_id' => $order->id,
            'production_order_product_line_id' => $line->id,
            'finished_product_batch_id' => $test->lot->id,
            'warehouse_id' => $warehouseId,
            'units' => $units,
            'created_by' => $test->user->id,
        ]);
    }

    return $order->fresh();
}

/**
 * Completa (o envía a revisión) la OP con lo que mostraría la pantalla: el ingrediente tal cual lo planeado, 10 galones
 * envasados, los saldos, lo gastado de cada producto (`$actualKg`) y los lotes de la orden. `$batchRows` lleva por lote
 * lo que quedó; `$extra`, campos de más (los motivos de sobreconsumo, por ejemplo).
 *
 * @param  list<array<string, mixed>>|null  $batchRows  null: no envía la lista de lotes
 */
function opsComplete(object $test, ProductionOrder $order, ?array $batchRows, string $route = 'production-orders.complete', ?string $actualKg = '462.3', array $extra = []): TestResponse
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
        'product_lines' => $actualKg === null ? null : opsLineRows($order, $actualKg),
        'batch_consumptions' => $batchRows,
        ...$extra,
    ], fn ($value) => $value !== null));
}

/** Lo gastado de cada producto de la OP, como lo escribe la tabla de consumo. */
function opsLineRows(ProductionOrder $order, string $actualKg = '462.3'): array
{
    return ProductionOrderProductLine::query()
        ->where('production_order_id', $order->id)
        ->orderBy('id')
        ->get()
        ->map(fn (ProductionOrderProductLine $line): array => ['id' => $line->id, 'actual_kg' => $actualKg])
        ->all();
}

/** La fila de la pantalla de cada lote de la OP: si se da, lo que quedó pesado en un envase abierto. */
function opsRows(ProductionOrder $order, ?string $leftoverKg = null): array
{
    return ProductionOrderBatchConsumption::query()
        ->where('production_order_id', $order->id)
        ->orderBy('id')
        ->get()
        ->map(fn (ProductionOrderBatchConsumption $source): array => array_filter([
            'id' => $source->id,
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

// --- Crear la OP: líneas sin fuentes ---

it('copies each product line of the formula with its kilos and no sources: nothing is committed when the order is created', function () {
    // Hay saldo y lotes libres del blanco en la bodega: crear la OP no elige ninguno.
    opsOriginRemnant($this, '30');
    opsOtherOrigin($this, 1500, '2026-10-01', 10);

    $order = opsRalOrder($this, lotUnits: 0);
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();

    expect($line->origin)->toBe(ProductionOrderProductLineOrigin::Formula)
        ->and($line->planned_kg)->toBe('462.3000')
        ->and($line->step_order)->toBe(2)
        ->and($line->actual_kg)->toBeNull()
        ->and(RemnantConsumption::query()->where('target_order_id', $order->id)->exists())->toBeFalse()
        ->and(ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->exists())->toBeFalse()
        // Nada comprometido: el lote sigue con sus 25 cuñetes libres para vender.
        ->and(app(AvailableBatchesService::class)->freeUnits($this->lot->id, $this->warehouse->id))->toBe('25');
});

it('plans the kilos of a product line written in grams', function () {
    $gram = UnitOfMeasure::create(['code' => 'g', 'name' => 'Gramo', 'symbol' => 'g', 'to_kg_conversion' => 0.001]);
    $detail = FormulaDetail::query()->whereNotNull('product_id')->firstOrFail();
    $detail->update(['quantity' => 3000, 'unit_of_measure_id' => $gram->id]);

    expect(app(ProductLinePlanner::class)->plannedKg($detail->fresh(['unitOfMeasure', 'product']), '154.1'))->toBe('462.3000');
});

it('creates an order whose formula carries products, with its product lines and no sources', function () {
    opsOriginRemnant($this, '10');

    $this->post(route('production-orders.store'), [
        'product_id' => $this->ral->id,
        'formula_id' => $this->ralFormula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 10,
        'planned_date' => plantToday(),
        'packaging' => [['product_variant_id' => $this->ralGallon->id, 'planned_units' => 10]],
    ])->assertSessionHasNoErrors();

    $order = ProductionOrder::query()->where('product_id', $this->ral->id)->sole();
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();

    // 3 kg/gal × 10 gal = 30 kg, sin fuentes: de dónde sale lo elige una persona en la orden. La MP va a sus líneas.
    expect($line->origin)->toBe(ProductionOrderProductLineOrigin::Formula)
        ->and($line->planned_kg)->toBe('30.0000')
        ->and(RemnantConsumption::query()->where('target_order_id', $order->id)->exists())->toBeFalse()
        ->and(ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->exists())->toBeFalse()
        ->and(ProductionOrderDetail::query()->where('production_order_id', $order->id)->pluck('raw_material_id')->all())->toBe([$this->material->id]);
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
        // Lo gastado va por línea, como una MP.
        ->and(ProductionOrderProductLine::query()->where('production_order_id', $order->id)->value('actual_kg'))->toBe('462.3000')
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

    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

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
    $originSummaryBefore = app(ProductionOrderCostSummary::class)->forOrder($this->origin->fresh());

    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

    $remnant = ProductionRemnant::query()->where('source_order_id', $this->origin->id)->sole();
    $movement = $remnant->movements()->sole();
    $originSummaryAfter = app(ProductionOrderCostSummary::class)->forOrder($this->origin->fresh());

    // Lo original es lo que la OP de origen dejó de saldo al completarse (nada): su costo, ya cerrado, no cambia.
    expect($remnant->available_quantity_kg)->toBe('5.7000')
        ->and($remnant->original_quantity_kg)->toBe('0.0000')
        ->and($remnant->original_quantity_gallons)->toBe('0.0000')
        ->and($originSummaryAfter['remnant'])->toBeNull()
        ->and($originSummaryAfter)->toBe($originSummaryBefore)
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
    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

    expect($originRemnant->fresh()->available_quantity_kg)->toBe('5.7000')
        ->and($originRemnant->fresh()->status)->toBe($expected);
})->with([
    'consumido por otra OP' => [true, RemnantStatus::PartiallyConsumed],
    'dado de baja' => [false, RemnantStatus::Available],
]);

it('does not complete when the origin remnant is in another warehouse', function () {
    $originRemnant = opsOriginRemnant($this, '10', $this->otherWarehouse);
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '5.7'))
        ->assertSessionHasErrors(['batch_consumptions' => __('Lo que quedó del lote :lot vuelve al saldo de :origin, y ese saldo está en otra bodega (:warehouse): no se pueden juntar, así que la orden no se puede completar.', [
            'lot' => 1682, 'origin' => 'OP-2026-0001', 'warehouse' => 'Almacén Norte',
        ])]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and($originRemnant->fresh()->available_quantity_kg)->toBe('10.0000')
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $this->lot->id)->value('quantity'))->toBe(25);
});

it('rejects a leftover heavier than one unit, or a leftover without its weight', function () {
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '23.5'))
        ->assertSessionHasErrors(['batch_consumptions.0.leftover_kg']);

    $rows = opsRows($order);
    $rows[0]['has_leftover'] = true;
    opsComplete($this, $order, $rows)->assertSessionHasErrors(['batch_consumptions.0.leftover_kg']);

    // Lo gastado de cada producto es obligatorio, como lo real de una MP.
    $response = $this->post(route('production-orders.complete', $order), [
        'density_kg_per_gallon' => 5,
        'quality_responsible_user_id' => $this->user->id,
        'line_adjustment_ids' => [],
        'remnant_consumption_ids' => [],
        'ingredients' => [['id' => ProductionOrderDetail::query()->where('production_order_id', $order->id)->value('id'), 'actual_quantity' => 10]],
        'packaging' => [['id' => ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->value('id'), 'actual_units' => 10]],
        'product_lines' => [['id' => ProductionOrderProductLine::query()->where('production_order_id', $order->id)->value('id')]],
        'batch_consumptions' => opsRows($order),
    ]);
    $response->assertSessionHasErrors(['product_lines.0.actual_kg']);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);
});

it('does not submit or complete while a formula line has no source', function (string $route) {
    $order = opsRalOrder($this);
    ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->delete();

    opsComplete($this, $order, [], $route)
        ->assertSessionHasErrors(['product_lines' => __('Falta de dónde sale :products: agrega un saldo o un lote a cada producto de la fórmula.', ['products' => 'ESMALTE BLANCO'])]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);
})->with(['completar' => 'production-orders.complete', 'enviar a revisión' => 'production-orders.submit-for-review']);

it('saves what was spent and the leftover when submitting for review, and the reviewer completes with them', function () {
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order, '5.7'), 'production-orders.submit-for-review')->assertSessionHasNoErrors();

    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::PendingReview)
        ->and(ProductionOrderProductLine::query()->where('production_order_id', $order->id)->value('actual_kg'))->toBe('462.3000')
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

it('warns that the order changed when the screen did not send what was spent of a product line', function (string $route) {
    $order = opsRalOrder($this);

    // La pantalla cargó antes de que la orden tuviera la línea: no manda su renglón.
    opsComplete($this, $order, opsRows($order), $route, actualKg: null)
        ->assertSessionHasErrors(['product_lines' => __(ProductionOrderCompletionLines::ORDER_CHANGED_MESSAGE)]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and(ProductionOrderProductLine::query()->where('production_order_id', $order->id)->value('actual_kg'))->toBeNull();
})->with(['completar' => 'production-orders.complete', 'enviar a revisión' => 'production-orders.submit-for-review']);

it('previews the cost of each product row of the consumption table, the same as its lots', function () {
    $order = opsRalOrder($this);
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $detail = ProductionOrderDetail::query()->where('production_order_id', $order->id)->firstOrFail();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->firstOrFail();

    $preview = $this->postJson(route('production-orders.preview-costs', $order), [
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 10]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
        'batch_consumptions' => [['id' => $source->id, 'has_leftover' => true, 'leftover_kg' => '5.7']],
    ])->assertOk()->json();

    // El renglón del producto cuesta lo que sus fuentes: los 20 cuñetes menos lo que vuelve al saldo. El costo por kilo
    // es sobre lo que salió de ellos (468 − 5,7 = 462,3 kg), así la fila cuadra consigo misma.
    expect($preview['product_lines'])->toHaveCount(1)
        ->and($preview['product_lines'][0]['id'])->toBe($line->id)
        ->and($preview['product_lines'][0]['cost'])->toBe('4491297.0844')
        ->and($preview['product_lines'][0]['cost'])->toBe($preview['summary']['bulk']['products_used'])
        ->and($preview['product_lines'][0]['unit_cost'])->toBe(bcdiv('4491297.0844', '462.3', 4));

    // Guardado al enviar a revisión: el detalle da el mismo costo por kilo.
    opsComplete($this, $order, opsRows($order, '5.7'), 'production-orders.submit-for-review')->assertSessionHasNoErrors();
    $this->get(route('production-orders.show', $order))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.product_lines.0.cost', '4491297.0844')
            ->where('order.product_lines.0.unit_cost', bcdiv('4491297.0844', '462.3', 4)));
});

it('ignores in the preview a lot that someone else removed while the screen was open', function () {
    $order = opsRalOrder($this);
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $detail = ProductionOrderDetail::query()->where('production_order_id', $order->id)->firstOrFail();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->firstOrFail();
    $staleId = $source->id;
    // Otra pestaña u otra persona quitó el lote; esta pantalla todavía lo manda.
    $source->delete();

    $preview = $this->postJson(route('production-orders.preview-costs', $order), [
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 10]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
        'batch_consumptions' => [['id' => $staleId, 'has_leftover' => true, 'leftover_kg' => '5.7']],
    ])->assertOk()->json();

    // Sin el lote, la línea no tiene de dónde salir: no cuesta nada (completar sí avisa que la orden cambió).
    expect($preview['products'])->toBe([])
        ->and($preview['summary']['bulk']['products_used'])->toBe('0.0000');
});

it('answers on the line id when the screen sends a product line the order no longer has', function () {
    $order = opsRalOrder($this);
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();

    // La pantalla manda su línea y otra que ya se borró (le quitaron su última fuente): la tarjeta pinta esta llave.
    $this->post(route('production-orders.complete', $order), [
        'product_lines' => [['id' => $line->id, 'actual_kg' => '462.3'], ['id' => $line->id + 1000, 'actual_kg' => '1']],
    ])->assertSessionHasErrors(['product_lines.1.id' => __(ProductionOrderCompletionLines::ORDER_CHANGED_MESSAGE)]);
});

it('caps a leftover typed heavier than one unit in the preview, so the cost never goes negative', function () {
    $order = opsRalOrder($this);
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $detail = ProductionOrderDetail::query()->where('production_order_id', $order->id)->firstOrFail();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->firstOrFail();
    $preview = fn (string $leftoverKg): array => $this->postJson(route('production-orders.preview-costs', $order), [
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 10]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
        'batch_consumptions' => [['id' => $source->id, 'has_leftover' => true, 'leftover_kg' => $leftoverKg]],
    ])->assertOk()->json();

    // 200 kg escritos en un cuñete de 23,4: cuenta como el cuñete entero (completar lo rechaza con su mensaje).
    $typedTooMuch = $preview('200');

    expect($typedTooMuch['product_lines'][0]['cost'])->toBe($preview('23.4')['product_lines'][0]['cost'])
        ->and(bccomp($typedTooMuch['product_lines'][0]['cost'], '0', 4))->toBe(1)
        ->and(bccomp($typedTooMuch['summary']['bulk']['products_used'], '0', 4))->toBe(1);
});

it('does not tell what is missing nor a cost per kilo when a lot has no weight per gallon', function () {
    $order = opsRalOrder($this);
    // La OP de origen sin peso por galón (un lote viejo): no se sabe cuántos kilos trae cada cuñete.
    $this->origin->update(['density_kg_per_gallon' => null]);

    $this->get(route('production-orders.show', $order))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.product_lines.0.has_unknown_kg', true)
            ->where('order.product_lines.0.missing_kg', null)
            // El costo sí se sabe (unidades × costo de traslado); el costo por kilo no.
            ->where('order.product_lines.0.cost', '4538165.7700')
            ->where('order.product_lines.0.unit_cost', null));
});

it('prints the product inside its step with its lot, and the open bucket line only while producing', function () {
    $order = opsRalOrder($this);
    $lot = sprintf('Lote %s (%s) · 20 × %s', $this->origin->lot_number, $this->origin->order_number, $this->lot->productVariant->presentation_label);
    $sheets = function () use ($order): array {
        $payload = app(BuildProductionOrderExportDataAction::class)->execute($order->fresh());

        return [
            $payload,
            view('pdf.production-order', ['order' => $payload, 'logoBase64' => null, 'generatedAt' => '-'])->render(),
            view('excel.production-order', ['order' => $payload])->render(),
        ];
    };

    [$payload, $pdf, $excel] = $sheets();
    $productRow = collect($payload['pdf_materials']['rows'])->firstWhere('kind', 'product');

    expect($productRow['step_order'])->toBe(2)
        ->and($productRow['raw_material_code'])->toBe('ESMALTE BLANCO')
        ->and($productRow['observations'])->toBe([$lot, 'Quedó en cuñete abierto: ________ kg']);
    foreach ([$pdf, $excel] as $html) {
        expect($html)->toContain($lot)->toContain('Quedó en cuñete abierto: ________ kg');
    }

    // En revisión lo que quedó ya está registrado: se imprime el dato, no la raya.
    opsComplete($this, $order, opsRows($order, '5.7'), 'production-orders.submit-for-review')->assertSessionHasNoErrors();
    [, $pdf, $excel] = $sheets();
    $leftover = "Quedaron 5,7 kg en un cuñete abierto del lote {$this->origin->lot_number}";

    foreach ([$pdf, $excel] as $html) {
        expect($html)->toContain($lot)->toContain($leftover)->not->toContain('Quedó en cuñete abierto: ________ kg');
    }
});

it('leaves a line to write where the product comes from when nobody chose a source', function () {
    $order = opsRalOrder($this);
    ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->delete();

    $row = collect(app(BuildProductionOrderExportDataAction::class)->execute($order->fresh())['pdf_materials']['rows'])
        ->firstWhere('kind', 'product');

    // Sin lote elegido tampoco va la raya del cuñete: si sobra algo, la planta lo anota en la misma celda.
    expect($row['observations'])->toBe(['De dónde sale: ______________________']);
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

it('does not estimate a lot with a leftover when its origin order has no weight per gallon', function () {
    $order = opsRalOrder($this);
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->orderBy('id')->firstOrFail();

    // Sin lo que quedó: unidades × costo de traslado.
    expect(app(BatchSourceDrawdown::class)->lineCost($source->fresh()))->not->toBeNull();

    // Completar rechaza lo que quedó sin peso por galón: la vista previa tampoco lo estima.
    $source->update(['used_kg' => '462.3', 'leftover_kg' => '5.7']);
    $this->origin->update(['density_kg_per_gallon' => null]);

    expect(app(BatchSourceDrawdown::class)->lineCost($source->fresh()))->toBeNull();
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

    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

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
    // La línea sale también del saldo: así se bloquean saldos y lotes al completar.
    RemnantConsumption::create([
        'remnant_id' => $originRemnant->id,
        'target_order_id' => $order->id,
        'production_order_product_line_id' => ProductionOrderProductLine::query()->where('production_order_id', $order->id)->value('id'),
        'quantity_kg' => '10',
        'quantity_gallons' => '1.7094',
        'consumed_by' => $this->user->id,
        'consumed_at' => now(),
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $queries[] = $query->sql;
        }
    });

    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

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

// --- Pantalla ---

it('sends the product lines with their sources and what is free, with costs only for costs.view', function (SystemRole $role, bool $withCosts) {
    $order = opsRalOrder($this);
    // Un saldo libre: se ofrece en el buscador.
    $remnant = opsOriginRemnant($this, '50');
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();

    $this->actingAs(userWithRole($role, ['email_verified_at' => now()]))
        ->get(route('production-orders.show', $order))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($line, $source, $remnant, $withCosts) {
            $page->where('can.planRemnant', true)
                // 462,3 kg planeados; 20 cuñetes de 23,4 kg cubren 468.
                ->where('order.product_lines.0.id', $line->id)
                ->where('order.product_lines.0.product_name', 'ESMALTE BLANCO')
                ->where('order.product_lines.0.origin', 'formula')
                ->where('order.product_lines.0.step_order', 2)
                ->where('order.product_lines.0.planned_kg', '462.3000')
                ->where('order.product_lines.0.covered_kg', '468.0000')
                ->where('order.product_lines.0.missing_kg', '0.0000')
                ->where('order.product_lines.0.has_unknown_kg', false)
                ->where('order.product_lines.0.actual_kg', null)
                ->where('order.batch_consumptions.0.id', $source->id)
                ->where('order.batch_consumptions.0.production_order_product_line_id', $line->id)
                ->where('order.batch_consumptions.0.lot_number', 1682)
                ->where('order.batch_consumptions.0.source_order_number', 'OP-2026-0001')
                ->where('order.batch_consumptions.0.presentation_label', 'Cuñete 4G')
                ->where('order.batch_consumptions.0.units', 20)
                ->where('order.batch_consumptions.0.kg_per_unit', '23.4000')
                ->where('order.batch_consumptions.0.kg', '468.0000')
                ->where('order.batch_consumptions.0.state', 'planned')
                ->where('order.batch_consumptions.0.can', ['update' => true, 'remove' => true])
                // Lo libre de la bodega para el buscador: quedan 5 cuñetes del lote y el saldo entero.
                ->where('order.available_batches.0.id', $this->lot->id)
                ->where('order.available_batches.0.product_id', $this->white->id)
                ->where('order.available_batches.0.free_units', '5')
                ->where('order.available_batches.0.kg_per_unit', '23.4000')
                ->where('order.available_remnants.0.id', $remnant->id)
                ->where('order.available_remnants.0.product_id', $this->white->id);

            if ($withCosts) {
                $page->where('order.batch_consumptions.0.transfer_unit_cost', '226908.2885')
                    ->where('order.batch_consumptions.0.consumed_cost', '4538165.7700')
                    // El renglón del producto en la tabla: el costo de sus fuentes y ese costo por kilo (÷ 468).
                    ->where('order.product_lines.0.cost', '4538165.7700')
                    ->where('order.product_lines.0.unit_cost', '9696.9354')
                    ->where('order.available_batches.0.transfer_unit_cost', '226908.2885')
                    ->where('order.cost_summary.bulk.products_used', '4538165.7700');
            } else {
                $page->missing('order.batch_consumptions.0.transfer_unit_cost')
                    ->missing('order.batch_consumptions.0.consumed_cost')
                    ->missing('order.product_lines.0.cost')
                    ->missing('order.available_batches.0.transfer_unit_cost')
                    ->missing('order.cost_summary');
            }
        });
})->with([
    'con costs.view' => [SystemRole::Admin, true],
    'sin costs.view' => [SystemRole::Production, false],
]);

it('shows a completed order read-only, with what was used and nothing to add', function () {
    $order = opsRalOrder($this);
    RemnantConsumption::query()->where('target_order_id', $order->id)->delete();
    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

    $this->get(route('production-orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.planRemnant', false)
            ->where('order.batch_consumptions.0.state', 'applied')
            ->where('order.product_lines.0.actual_kg', '462.3000')
            ->where('order.batch_consumptions.0.leftover_kg', '5.7000')
            ->where('order.batch_consumptions.0.consumed_cost', '4491297.0844')
            ->where('order.batch_consumptions.0.can', ['update' => false, 'remove' => false])
            ->where('order.available_batches', [])
            // El desglose suma sus partes: MP 50 + lotes.
            ->where('order.cost_summary.bulk.products_used', '4491297.0844')
            ->where('order.cost_summary.bulk.total', '4491347.0844'));
});

it('previews the cost with the leftover being typed, not the saved one', function () {
    $order = opsRalOrder($this);
    $source = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    $detail = ProductionOrderDetail::query()->where('production_order_id', $order->id)->firstOrFail();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->firstOrFail();
    $preview = fn (?array $rows) => $this->postJson(route('production-orders.preview-costs', $order), array_filter([
        'ingredients' => [['id' => $detail->id, 'actual_quantity' => 10]],
        'packaging' => [['id' => $plan->id, 'actual_units' => 10]],
        'batch_consumptions' => $rows,
    ], fn ($value) => $value !== null))->assertOk()->json();

    // «Sí, 5,7 kg» escrito y sin guardar: el costo es el de completar (20 cuñetes − lo que vuelve al saldo).
    $typed = $preview([['id' => $source->id, 'has_leftover' => true, 'leftover_kg' => '5.7']]);
    expect($typed['summary']['bulk']['products_used'])->toBe('4491297.0844')
        ->and($typed['products'])->toBe([['id' => $source->id, 'cost' => '4491297.0844']])
        ->and($source->fresh()->leftover_kg)->toBeNull();

    // Guardado «sí, 5,7» al enviar; quien aprueba responde «no» sin guardar: vuelven a pagarse los 20 cuñetes enteros.
    $source->update(['used_kg' => '462.3', 'leftover_kg' => '5.7']);
    expect($preview([['id' => $source->id, 'has_leftover' => false, 'leftover_kg' => '5.7']])['summary']['bulk']['products_used'])->toBe('4538165.7700')
        // Sin la lista, lo guardado.
        ->and($preview(null)['summary']['bulk']['products_used'])->toBe('4491297.0844');

    // Con lo que quedó y la OP de origen sin peso por galón no se estima: no suma y la fila lo dice.
    $source->update(['leftover_kg' => null]);
    $this->origin->update(['density_kg_per_gallon' => null]);
    $unknown = $preview([['id' => $source->id, 'has_leftover' => true, 'leftover_kg' => '5.7']]);
    expect($unknown['products'])->toBe([['id' => $source->id, 'cost' => null]])
        ->and($unknown['summary']['bulk']['products_used'])->toBe('0.0000');
});

it('tells in Remnants that the available kilos pass what the order left because of opened buckets', function () {
    $order = opsRalOrder($this);
    // El saldo de la OP de origen nace después de crear la OP: no se preseleccionó.
    $originRemnant = opsOriginRemnant($this, '10');
    // Otro saldo que nunca recibió nada: no lleva la aclaración.
    $untouched = opsOtherOrigin($this, 1700, '2026-10-01', 1);
    $plainRemnant = ProductionRemnant::create([
        'source_order_id' => $untouched->production_order_id,
        'product_id' => $this->white->id,
        'warehouse_id' => $this->warehouse->id,
        'original_quantity_gallons' => '0.5128',
        'original_quantity_kg' => '3',
        'available_quantity_gallons' => '0.5128',
        'available_quantity_kg' => '3',
        'density_kg_per_gallon' => '5.85',
        'cost_per_gallon' => '48102.0721',
        'status' => RemnantStatus::Available,
        'created_by' => $this->user->id,
    ]);

    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();

    $this->get(route('production.remnants.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('remnants.data', fn ($rows) => collect($rows)->firstWhere('id', $originRemnant->id)['opened_units_kg'] === '5.7000'
                && collect($rows)->firstWhere('id', $plainRemnant->id)['opened_units_kg'] === null));

    $this->get(route('production.remnants.show', $originRemnant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('remnant.original_quantity_kg', '10.0000')
            ->where('remnant.available_quantity_kg', '15.7000')
            ->where('remnant.opened_units_kg', '5.7000')
            ->where('remnant.exceeds_original', true)
            // «Cuñete abierto en OP-…», +5,7 kg.
            ->where('movements.0.type', 'opened_unit')
            ->where('movements.0.direction', 'in')
            ->where('movements.0.quantity_kg', '5.7000')
            ->where('movements.0.production_order.order_number', $order->order_number));
});

it('shows a remnant born from an opened bucket with nothing original, without looking like an error', function () {
    $order = opsRalOrder($this);
    opsComplete($this, $order, opsRows($order, '5.7'))->assertSessionHasNoErrors();
    $remnant = ProductionRemnant::query()->where('source_order_id', $this->origin->id)->sole();

    $this->get(route('production.remnants.show', $remnant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('remnant.original_quantity_kg', '0.0000')
            ->where('remnant.available_quantity_kg', '5.7000')
            ->where('remnant.opened_units_kg', '5.7000')
            ->where('remnant.exceeds_original', true)
            ->where('movements.0.type', 'opened_unit'));
});

it('runs the second plant case: matte black into 2-in-1 matte, 10 whole buckets chosen by hand and reused containers', function () {
    $category = ProductCategory::create(['name' => 'Esmaltes alquídicos']);
    $product = fn (string $name): Product => Product::create([
        'name' => $name,
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->gallon->id,
        'current_cost' => 30000,
        'cif_percentage' => 15,
        'current_price' => 60000,
        'price_threshold' => 5,
    ]);
    $black = $product('ESMALTE ALQUIDICO NEGRO MATE');
    $twoInOne = $product('ESMALTE 2 EN 1 MATE');
    $blackBucket = ProductVariant::create([
        'product_id' => $black->id, 'code' => 'NM-5G', 'unit_of_measure_id' => $this->gallon->id,
        'presentation_value' => 5, 'presentation_label' => 'Cuñete 5G', 'is_active' => true,
    ]);
    $container = RawMaterial::factory()->create(['code' => 'CUNETE-BP', 'category_id' => RawMaterialCategory::factory()->container()->create()->id]);
    InventoryBatch::create(['raw_material_id' => $container->id, 'warehouse_id' => $this->warehouse->id, 'initial_quantity' => 50, 'remaining_quantity' => 50, 'unit_price' => 8000, 'entry_date' => now()]);
    $label = RawMaterial::factory()->withoutInventoryTracking()->create([
        'code' => 'ETQ-CUNETE', 'category_id' => RawMaterialCategory::factory()->label()->create()->id, 'current_price' => 300,
    ]);
    $twoInOneBucket = ProductVariant::create([
        'product_id' => $twoInOne->id, 'code' => '2E1-5G', 'unit_of_measure_id' => $this->gallon->id,
        'presentation_value' => 5, 'presentation_label' => 'Cuñete 5G', 'is_active' => true,
        'package_raw_material_id' => $container->id, 'label_raw_material_id' => $label->id,
    ]);
    $material = function (string $code): RawMaterial {
        $rawMaterial = RawMaterial::create(['code' => $code, 'unit_of_measure_id' => $this->kilo->id, 'current_price' => 5]);
        InventoryBatch::create(['raw_material_id' => $rawMaterial->id, 'warehouse_id' => $this->warehouse->id, 'initial_quantity' => 100, 'remaining_quantity' => 100, 'unit_price' => 5, 'entry_date' => now()]);

        return $rawMaterial;
    };
    $abs = $material('ABS-7410');
    $c6 = $material('C-6');

    // Origen: OP 267, lote 1634, cuñetes de 5 gal × 4,1 kg/gal = 20,5 kg; 34 cuñetes y un saldo de 2 kg.
    $blackFormula = Formula::create(['product_id' => $black->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->user->id]);
    $origin = ProductionOrder::create([
        'order_number' => 'OP-2026-0267', 'lot_number' => 1634, 'product_id' => $black->id, 'formula_id' => $blackFormula->id,
        'warehouse_id' => $this->warehouse->id, 'quantity' => 172, 'status' => ProductionOrderStatus::Completed->value,
        'planned_date' => now(), 'created_by' => $this->user->id, 'density_kg_per_gallon' => '4.1', 'cif_percentage' => '15',
    ]);
    $blackLot = opsBatch($this, $origin, units: 34, transferUnitCost: '150000', variant: $blackBucket);
    $blackRemnant = ProductionRemnant::create([
        'source_order_id' => $origin->id, 'product_id' => $black->id, 'warehouse_id' => $this->warehouse->id,
        'original_quantity_gallons' => '0.4878', 'original_quantity_kg' => '2', 'available_quantity_gallons' => '0.4878',
        'available_quantity_kg' => '2', 'density_kg_per_gallon' => '4.1', 'cost_per_gallon' => '34500',
        'status' => RemnantStatus::Available, 'created_by' => $this->user->id,
    ]);

    // La fórmula de la jefa, por galón: negro 4,1 kg (se costea a granel: el envase se reutiliza), ABS-7410 14 g, C-6 100 g.
    $formula = Formula::create(['product_id' => $twoInOne->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->user->id]);
    FormulaDetail::create(['formula_id' => $formula->id, 'product_id' => $black->id, 'costing_basis' => FormulaLineCostingBasis::Bulk, 'quantity' => 4.1, 'unit_of_measure_id' => $this->kilo->id, 'step_order' => 1]);
    FormulaDetail::create(['formula_id' => $formula->id, 'raw_material_id' => $abs->id, 'quantity' => 0.014, 'unit_of_measure_id' => $this->kilo->id, 'step_order' => 2]);
    FormulaDetail::create(['formula_id' => $formula->id, 'raw_material_id' => $c6->id, 'quantity' => 0.1, 'unit_of_measure_id' => $this->kilo->id, 'step_order' => 3]);

    // OP de 50 gal desde la pantalla de crear: línea de 205 kg.
    $this->post(route('production-orders.store'), [
        'product_id' => $twoInOne->id,
        'formula_id' => $formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 50,
        'planned_date' => plantToday(),
        'packaging' => [['product_variant_id' => $twoInOneBucket->id, 'planned_units' => 10]],
    ])->assertSessionHasNoErrors();

    $order = ProductionOrder::query()->where('product_id', $twoInOne->id)->sole();
    $line = ProductionOrderProductLine::query()->where('production_order_id', $order->id)->sole();

    // La OP nace sin fuentes: el saldo de 2 kg no se toca solo. La planta transforma cuñetes enteros: se eligen 10 del lote.
    expect($line->planned_kg)->toBe('205.0000')
        ->and(RemnantConsumption::query()->where('target_order_id', $order->id)->exists())->toBeFalse();
    $this->post(route('production-orders.batch-consumptions.store', $order), [
        'finished_product_batch_id' => $blackLot->id,
        'units' => 10,
        'production_order_product_line_id' => $line->id,
    ])->assertSessionHasNoErrors();
    $lotSource = ProductionOrderBatchConsumption::query()->where('production_order_id', $order->id)->sole();
    expect($lotSource->units)->toBe(10);

    $this->post(route('production-orders.start', $order))->assertSessionHasNoErrors();

    $details = ProductionOrderDetail::query()->where('production_order_id', $order->id)->orderBy('step_order')->get();
    $plan = ProductionOrderPackagingPlan::query()->where('production_order_id', $order->id)->sole();

    $this->post(route('production-orders.complete', $order), [
        'density_kg_per_gallon' => '4.1',
        'quality_responsible_user_id' => $this->user->id,
        'line_adjustment_ids' => [],
        'remnant_consumption_ids' => [],
        // ABS-7410: 0,014 × 50 = 0,7 kg; C-6: 0,1 × 50 = 5 kg.
        'ingredients' => [['id' => $details[0]->id, 'actual_quantity' => '0.7'], ['id' => $details[1]->id, 'actual_quantity' => '5']],
        // 10 cuñetes de 2 en 1: envases nuevos 0 (se reusan los del negro) y 10 etiquetas.
        'packaging' => [['id' => $plan->id, 'actual_units' => 10, 'new_containers_used' => 0, 'labels_used' => 10, 'label_raw_material_id' => $label->id]],
        // Lo gastado del negro, como una MP; «¿Quedó producto?» No.
        'product_lines' => [['id' => $line->id, 'actual_kg' => '205']],
        'batch_consumptions' => [['id' => $lotSource->id, 'has_leftover' => false]],
    ])->assertSessionHasNoErrors();

    $exit = FinishedInventoryMovement::query()
        ->where('finished_product_batch_id', $blackLot->id)
        ->where('type', InventoryMovementType::Exit->value)
        ->sole();

    // Salen 10 cuñetes del lote 1634 como «Transformación» de esta OP; el saldo de 2 kg sigue entero; no se usó ningún envase.
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Completed)
        ->and($exit->reason)->toBe(FinishedInventoryMovementReason::Transformation)
        ->and((int) $exit->production_order_id)->toBe($order->id)
        ->and($exit->quantity)->toBe(10)
        ->and(FinishedProductBatchStock::query()->where('finished_product_batch_id', $blackLot->id)->value('quantity'))->toBe(24)
        ->and($blackRemnant->fresh()->available_quantity_kg)->toBe('2.0000')
        ->and($lotSource->fresh()->consumed_cost)->toBe('1500000.0000')
        ->and($lotSource->fresh()->leftover_kg)->toBeNull()
        ->and((string) InventoryBatch::query()->where('raw_material_id', $container->id)->value('remaining_quantity'))->toBe('50.0000')
        ->and($plan->fresh()->labels_used)->toBe(10);
});

// --- E2a: faltantes del producto al crear y tolerancia de consumo ---

/** Crea la OP de RAL 7016 por la ruta, sin aceptar faltantes: `$gallons` × 3 kg de blanco. */
function opsStoreRal(object $test, string $gallons, bool $confirm = false): TestResponse
{
    return $test->from(route('production-orders.create'))->post(route('production-orders.store'), [
        'product_id' => $test->ral->id,
        'formula_id' => $test->ralFormula->id,
        'warehouse_id' => $test->warehouse->id,
        'quantity' => $gallons,
        'planned_date' => plantToday(),
        'packaging' => [['product_variant_id' => $test->ralGallon->id, 'planned_units' => 10]],
        'confirm_stock_shortage' => $confirm,
    ]);
}

it('warns at creation about the finished product the formula needs and the warehouse does not have free', function () {
    opsOriginRemnant($this, '10');

    // 300 gal × 3 kg = 900 kg de blanco; libres: el saldo de 10 kg y 25 cuñetes de 23,4 kg (585). La MP sí alcanza.
    opsStoreRal($this, '300')
        ->assertRedirect(route('production-orders.create'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('stockShortages', [])
        ->assertInertiaFlash('productShortages', [[
            'product_id' => $this->white->id,
            'name' => 'ESMALTE BLANCO',
            'unit' => 'kg',
            'required' => '900.0000',
            'available' => '595.0000',
            'remnants_kg' => '10.0000',
            'lots_kg' => '585.0000',
            'has_unknown_kg' => false,
            'missing' => '305.0000',
        ]]);

    expect(ProductionOrder::query()->where('product_id', $this->ral->id)->exists())->toBeFalse();

    // Aceptado, la orden se crea igual: el faltante avisa, no impide.
    opsStoreRal($this, '300', confirm: true)->assertSessionHasNoErrors();
    expect(ProductionOrder::query()->where('product_id', $this->ral->id)->count())->toBe(1);
});

it('does not warn when the free remnants and lots cover the product', function () {
    // 10 gal × 3 = 30 kg; hay 585 kg libres en el lote.
    opsStoreRal($this, '10')->assertInertiaFlashMissing('productShortages');

    expect(ProductionOrder::query()->where('product_id', $this->ral->id)->count())->toBe(1);
});

it('does not count what another open order committed, nor the kilos of a lot without weight per gallon', function () {
    // Otra OP abierta comprometió 20 de los 25 cuñetes: quedan 5 libres (117 kg).
    opsRalOrder($this);

    opsStoreRal($this, '300')->assertInertiaFlash('productShortages.0.lots_kg', '117.0000')
        ->assertInertiaFlash('productShortages.0.missing', '783.0000');

    // Sin peso por galón en la OP del lote, sus kilos no se saben: no suman y el faltante lo dice.
    $this->origin->update(['density_kg_per_gallon' => null]);

    opsStoreRal($this, '300')->assertInertiaFlash('productShortages.0.lots_kg', '0.0000')
        ->assertInertiaFlash('productShortages.0.has_unknown_kg', true)
        ->assertInertiaFlash('productShortages.0.missing', '900.0000');
});

it('asks why when the product used passes the tolerance, on submitting and on completing', function (string $route) {
    config(['production.consumption_tolerance_percentage' => '10.00']);
    $order = opsRalOrder($this);

    // 462,3 planeados × 1,10 = 508,53: justo en el límite no se pasa.
    opsComplete($this, $order, opsRows($order), $route, actualKg: '508.53')->assertSessionHasNoErrors();
})->with(['completar' => 'production-orders.complete', 'enviar a revisión' => 'production-orders.submit-for-review']);

it('rejects without the reason of a product that passed the tolerance, and saves it apart from the raw materials', function () {
    config(['production.consumption_tolerance_percentage' => '10.00']);
    $order = opsRalOrder($this);
    $key = "product_overconsumption_reasons.{$this->white->id}";

    opsComplete($this, $order, opsRows($order), 'production-orders.submit-for-review', actualKg: '510')
        ->assertSessionHasErrors([$key => 'ESMALTE BLANCO: 510 kg de 462,3 planeados (+10,3%). Se pasó más del 10% de lo planeado: escribe el motivo.']);
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);

    // Un motivo con la llave de las MP no sirve para el producto.
    opsComplete($this, $order, opsRows($order), 'production-orders.submit-for-review', actualKg: '510', extra: [
        'overconsumption_reasons' => [$this->white->id => 'Se botó un cuñete'],
    ])->assertSessionHasErrors([$key]);

    opsComplete($this, $order, opsRows($order), 'production-orders.submit-for-review', actualKg: '510', extra: [
        'product_overconsumption_reasons' => [$this->white->id => 'Se ajustó el tono con más blanco'],
    ])->assertSessionHasNoErrors();

    $reason = ProductionOrderConsumptionReason::query()->where('production_order_id', $order->id)->sole();
    expect($reason->product_id)->toBe($this->white->id)
        ->and($reason->raw_material_id)->toBeNull()
        ->and($reason->reason)->toBe('Se ajustó el tono con más blanco');

    // En revisión, el detalle lo marca y trae el motivo para editarlo.
    $this->get(route('production-orders.show', $order))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.product_overconsumption.0.product_id', $this->white->id)
            ->where('order.product_overconsumption.0.consumed', '510.0000')
            ->where('order.product_overconsumption.0.needs_reason', true)
            ->where('order.product_overconsumption.0.reason', 'Se ajustó el tono con más blanco')
            ->where('order.product_consumption_reasons.0.product_id', $this->white->id)
            ->where('order.overconsumption', []));

    // Quien aprueba corrige lo gastado: ya no se pasa y el motivo se borra.
    opsComplete($this, $order, opsRows($order), actualKg: '462.3')->assertSessionHasNoErrors();
    expect(ProductionOrderConsumptionReason::query()->where('production_order_id', $order->id)->exists())->toBeFalse();
});

it('does not ask anything when less product was used, nor for a line added in the order without plan', function () {
    config(['production.consumption_tolerance_percentage' => '10.00']);
    $order = opsRalOrder($this);
    // La línea de la fórmula se vuelve agregada (sin plan): lo gastado no se compara con nada.
    $added = opsRalOrder($this, lotUnits: 1);
    ProductionOrderProductLine::query()->where('production_order_id', $added->id)->update(['planned_kg' => null, 'step_order' => null, 'origin' => ProductionOrderProductLineOrigin::Added]);

    opsComplete($this, $order, opsRows($order), actualKg: '300')->assertSessionHasNoErrors();
    opsComplete($this, $added, opsRows($added), actualKg: '900')->assertSessionHasNoErrors();
});

it('lists the product in the overconsumption alert with the cost of the excess', function () {
    config(['production.consumption_tolerance_percentage' => '10.00']);
    $order = opsRalOrder($this);

    opsComplete($this, $order, opsRows($order), actualKg: '510', extra: [
        'product_overconsumption_reasons' => [$this->white->id => 'Se ajustó el tono con más blanco'],
    ])->assertSessionHasNoErrors();

    // 47,7 kg de más × el costo por kilo de la línea (20 cuñetes ÷ 468 kg = 9.696,9354).
    $extraCost = app(DecimalCalculator::class)->round(bcmul('47.7', bcdiv('4538165.7700', '468', 12), 12), 4);
    $alert = Alert::query()->where('production_order_id', $order->id)->sole();

    expect($alert->message)->toContain('ESMALTE BLANCO +10,3% (510 de 462,3 kg, +'.NumberFormat::cost($extraCost).')');

    $this->get(route('production-orders.show', $order))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.product_overconsumption.0.extra_cost', $extraCost));
});
