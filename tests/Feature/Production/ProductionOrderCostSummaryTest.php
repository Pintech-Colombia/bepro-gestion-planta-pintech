<?php

declare(strict_types=1);

use App\Actions\Production\BuildProductionOrderExportDataAction;
use App\Enums\FinishedInventoryMovementReason;
use App\Enums\InventoryMovementType;
use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\FinishedInventoryMovement;
use App\Models\FinishedProductBatch;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderLineAdjustment;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductionOrderProductLine;
use App\Models\ProductionRemnant;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RemnantConsumption;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Production\ProductionOrderCifBackfill;
use App\Services\Production\ProductionOrderCostSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * El resumen de costos de la OP (tarjeta, vista previa, PDF y Excel): una sola cuenta en el servidor, igual en todos los
 * estados. La orden de estas pruebas usa 30 de MP a $5 (150), un ajuste de línea de 4 a $5 (20) y 20 kg de un saldo a
 * $5,5/gal (4 gal = 22): granel 192. Envasa 10 galones y deja 10 kg de sobrante (2 gal): 12 gal, $16 por galón.
 */

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

    $category = ProductCategory::create(['name' => 'Resumen']);
    $this->uom = UnitOfMeasure::create(['code' => 'gal', 'name' => 'Galon', 'symbol' => 'gal']);

    $this->warehouse = Warehouse::create(['name' => 'Planta Resumen', 'city' => 'Cali', 'type' => 'factory', 'is_active' => true]);

    $this->material = RawMaterial::create([
        'code' => 'RM-SUMMARY-'.uniqid(),
        'unit_of_measure_id' => $this->uom->id,
        'current_price' => 5,
    ]);

    InventoryBatch::create([
        'raw_material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'initial_quantity' => 200,
        'remaining_quantity' => 200,
        'unit_price' => 5,
        'entry_date' => now(),
    ]);

    $this->product = Product::create([
        'name' => 'Esmalte resumen',
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->uom->id,
        'current_cost' => 12,
        'cif_percentage' => 40,
        'current_price' => 16.8,
        'sales_margin' => 30,
        'price_threshold' => 5,
    ]);

    $this->formula = Formula::create([
        'product_id' => $this->product->id,
        'version' => 1,
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    FormulaDetail::create([
        'formula_id' => $this->formula->id,
        'raw_material_id' => $this->material->id,
        'quantity' => 3,
        'unit_of_measure_id' => $this->uom->id,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'code' => 'ESM-RES-GAL',
        'unit_of_measure_id' => $this->uom->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
        'current_price' => 20,
        'is_active' => true,
    ]);

    // El saldo que se usa: de otra orden, a $5,5 por galón.
    $sourceOrder = ProductionOrder::create([
        'order_number' => 'OP-SRC-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->product->id,
        'formula_id' => $this->formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 100,
        'status' => ProductionOrderStatus::Completed->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
        'density_kg_per_gallon' => 5,
    ]);

    $this->usedRemnant = ProductionRemnant::create([
        'source_order_id' => $sourceOrder->id,
        'product_id' => $this->product->id,
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

    $this->order = ProductionOrder::create([
        'order_number' => 'OP-SUM-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->product->id,
        'formula_id' => $this->formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 10,
        'status' => ProductionOrderStatus::InProgress->value,
        'planned_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $this->detail = ProductionOrderDetail::create([
        'production_order_id' => $this->order->id,
        'raw_material_id' => $this->material->id,
        'planned_quantity' => 30,
        'unit_cost' => 5,
        'total_cost' => 150,
    ]);

    $this->plan = ProductionOrderPackagingPlan::create([
        'production_order_id' => $this->order->id,
        'product_variant_id' => $this->variant->id,
        'planned_units' => 10,
    ]);
});

/** Ajuste de línea (4 a $5) y 20 kg del saldo planeados en la orden. */
function summaryOrderWithAdjustmentAndRemnant(object $test): void
{
    ProductionOrderLineAdjustment::create([
        'production_order_id' => $test->order->id,
        'raw_material_id' => $test->material->id,
        'quantity' => 4,
        'reason' => 'Corrección de viscosidad',
        'created_by' => $test->user->id,
    ]);

    $test->post(route('production-orders.consume-remnant', $test->order), [
        'remnant_id' => $test->usedRemnant->id,
        'quantity_kg' => 20,
    ])->assertSessionHasNoErrors();
}

/** Lo que digita la planta: lo mismo para la vista previa y para completar. */
function summaryTypedData(object $test): array
{
    return [
        'ingredients' => [['id' => $test->detail->id, 'actual_quantity' => 30]],
        'packaging' => [['id' => $test->plan->id, 'actual_units' => 10]],
        // Lo gastado de cada producto que lleva la orden: lo que traen sus saldos (el saldo crea su línea).
        'product_lines' => ProductionOrderProductLine::query()
            ->where('production_order_id', $test->order->id)
            ->get()
            ->map(fn (ProductionOrderProductLine $line): array => [
                'id' => $line->id,
                'actual_kg' => (string) $line->remnantConsumptions()->sum('quantity_kg'),
            ])
            ->all(),
        'remnant_quantity_kg' => 10,
        'density_kg_per_gallon' => 5,
    ];
}

function completeSummaryOrder(object $test): TestResponse
{
    return $test->post(route('production-orders.complete', $test->order), [
        ...summaryTypedData($test),
        'quality_responsible_user_id' => $test->user->id,
        'line_adjustment_ids' => $test->order->lineAdjustments()->pluck('id')->all(),
        'remnant_consumption_ids' => RemnantConsumption::query()->where('target_order_id', $test->order->id)->pluck('id')->all(),
    ]);
}

/** @return array<string, mixed> */
function shownCostSummary(object $test): array
{
    $summary = null;

    $test->get(route('production-orders.show', $test->order))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$summary) {
            $summary = $page->toArray()['props']['order']['cost_summary'];
        });

    return $summary;
}

it('the completed card shows the whole bulk cost saved, broken down into raw materials, adjustments and remnants', function () {
    summaryOrderWithAdjustmentAndRemnant($this);
    completeSummaryOrder($this)->assertSessionHasNoErrors();

    $saved = ProductionCost::query()->where('production_order_id', $this->order->id)->value('cost');
    $bulk = shownCostSummary($this)['bulk'];

    expect($saved)->toBe('192.0000')
        ->and($bulk['total'])->toBe($saved)
        ->and($bulk['raw_materials'])->toBe('150.0000')
        ->and($bulk['line_adjustments'])->toBe('20.0000')
        ->and($bulk['remnants_used'])->toBe('22.0000')
        ->and(bcadd(bcadd($bulk['raw_materials'], $bulk['line_adjustments'], 4), $bulk['remnants_used'], 4))->toBe($bulk['total']);
});

it('the preview and the completed order give the same summary for the same order', function () {
    summaryOrderWithAdjustmentAndRemnant($this);

    $preview = $this->postJson(route('production-orders.preview-costs', $this->order), summaryTypedData($this))
        ->assertOk()
        ->json('summary');

    completeSummaryOrder($this)->assertSessionHasNoErrors();
    $completed = shownCostSummary($this);

    expect($preview['basis'])->toBe('estimated')
        ->and($completed['basis'])->toBe('actual')
        ->and(Arr::except($completed, 'basis'))->toEqual(Arr::except($preview, 'basis'))
        // 192 ÷ 12 gal; con el 40 % de CIF; entre 5 kg/gal.
        ->and($completed['cost_per_gallon'])->toBe('16.0000')
        ->and($completed['cost_per_gallon_with_cif'])->toBe('22.4000')
        ->and($completed['cost_per_kg'])->toBe('3.2000')
        ->and($completed['packaged'])->toBe([
            'total' => '160.0000',
            'bulk' => '160.0000',
            'packaging_materials' => '0.0000',
            'total_with_cif' => '224.0000',
        ]);
});

it('a completed order with a remnant shows the remnant with CIF and the total with it, as the remnant saved it', function () {
    summaryOrderWithAdjustmentAndRemnant($this);
    completeSummaryOrder($this)->assertSessionHasNoErrors();

    $remnant = ProductionRemnant::query()->where('source_order_id', $this->order->id)->sole();
    $summary = shownCostSummary($this);

    expect($summary['remnant'])->toBe([
        'gallons' => '2.0000',
        'cost_with_cif' => bcmul($remnant->cost_per_gallon, $remnant->original_quantity_gallons, 4),
        'total_with_remnant' => '268.8000',
    ])
        ->and($remnant->cost_per_gallon)->toBe($summary['cost_per_gallon_with_cif']);
});

it('keeps the CIF of the day the order was completed', function () {
    completeSummaryOrder($this)->assertSessionHasNoErrors();
    $before = shownCostSummary($this);

    $this->product->update(['cif_percentage' => 80]);

    expect($this->order->fresh()->cif_percentage)->toBe('40.00')
        ->and($this->order->fresh()->cif_percentage_estimated)->toBeFalse()
        ->and(shownCostSummary($this))->toEqual($before)
        ->and($before['cost_per_gallon_with_cif'])->toBe(bcmul($before['cost_per_gallon'], '1.4', 4));
});

it('the backfill takes the CIF from a real lot of the order, then from its remnant, and otherwise from the product, marked', function () {
    completeSummaryOrder($this)->assertSessionHasNoErrors();

    // Una OP completada antes de la columna: sin CIF. El producto ya cambió el suyo.
    $this->order->forceFill(['cif_percentage' => null])->saveQuietly();
    $this->product->update(['cif_percentage' => 80]);

    // Solo con saldo (sin lote): el de su costo por galón contra el granel por galón.
    $remnantOnly = ProductionOrder::factory()->create([
        'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
        'status' => ProductionOrderStatus::Completed, 'created_by' => $this->user->id,
    ]);
    ProductionCost::create(['product_id' => $this->product->id, 'formula_id' => $remnantOnly->formula_id, 'production_order_id' => $remnantOnly->id, 'cost' => 100, 'unit_cost' => 10, 'calculated_at' => now()]);
    ProductionRemnant::factory()->create([
        'source_order_id' => $remnantOnly->id, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
        'cost_per_gallon' => '12.5', 'created_by' => $this->user->id,
    ]);

    // Sin lote ni saldo: el CIF del producto hoy, marcado como estimado.
    $bare = ProductionOrder::factory()->create([
        'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
        'status' => ProductionOrderStatus::Completed, 'created_by' => $this->user->id,
    ]);

    // Las tres de arriba y la orden de origen del saldo usado, también completada y sin CIF.
    expect(app(ProductionOrderCifBackfill::class)->run())->toBe(4);

    expect($this->order->fresh()->only(['cif_percentage', 'cif_percentage_estimated']))
        ->toBe(['cif_percentage' => '40.00', 'cif_percentage_estimated' => false])
        ->and($remnantOnly->fresh()->only(['cif_percentage', 'cif_percentage_estimated']))
        ->toBe(['cif_percentage' => '25.00', 'cif_percentage_estimated' => false])
        ->and($bare->fresh()->only(['cif_percentage', 'cif_percentage_estimated']))
        ->toBe(['cif_percentage' => '80.00', 'cif_percentage_estimated' => true])
        ->and(shownCostSummary($this)['cif_estimated'])->toBeFalse()
        // Correrlo otra vez no cambia nada.
        ->and(app(ProductionOrderCifBackfill::class)->run())->toBe(0);
});

/** El lote de la orden con un costo de traslado estimado después, con un CIF de 80 % (el del producto ese día). */
function estimateSummaryOrderBatch(object $test): void
{
    $entry = FinishedInventoryMovement::query()
        ->where('production_order_id', $test->order->id)
        ->where('type', InventoryMovementType::Entry)
        ->where('reason', FinishedInventoryMovementReason::Production)
        ->sole();

    FinishedProductBatch::query()->where('production_order_id', $test->order->id)->update([
        'transfer_unit_cost' => bcmul($entry->cost_price, '1.8', 4),
        'transfer_cost_estimated' => true,
    ]);
}

it('the backfill prefers the remnant to a lot whose transfer cost was estimated', function () {
    completeSummaryOrder($this)->assertSessionHasNoErrors();
    $this->order->forceFill(['cif_percentage' => null])->saveQuietly();
    estimateSummaryOrderBatch($this);

    app(ProductionOrderCifBackfill::class)->run();

    // El saldo guardó el CIF real del día (40 %); el lote, uno estimado después (80 %).
    expect($this->order->fresh()->only(['cif_percentage', 'cif_percentage_estimated']))
        ->toBe(['cif_percentage' => '40.00', 'cif_percentage_estimated' => false]);
});

it('the backfill marks as estimated a CIF taken from a lot whose transfer cost was estimated, without a remnant that sets it', function () {
    completeSummaryOrder($this)->assertSessionHasNoErrors();
    $this->order->forceFill(['cif_percentage' => null])->saveQuietly();
    estimateSummaryOrderBatch($this);
    // Un saldo sin costo por galón no fija ningún CIF.
    ProductionRemnant::query()->where('source_order_id', $this->order->id)->update(['cost_per_gallon' => null]);

    app(ProductionOrderCifBackfill::class)->run();

    expect($this->order->fresh()->only(['cif_percentage', 'cif_percentage_estimated']))
        ->toBe(['cif_percentage' => '80.00', 'cif_percentage_estimated' => true]);
});

it('a completed order without a saved CIF uses the CIF of the product today, marked as estimated, without writing it', function () {
    completeSummaryOrder($this)->assertSessionHasNoErrors();

    // Completada por la imagen anterior a la columna, después de que el relleno corrió.
    $this->order->forceFill(['cif_percentage' => null, 'cif_percentage_estimated' => false])->saveQuietly();
    $this->product->update(['cif_percentage' => 80]);

    $summary = shownCostSummary($this);

    expect($summary['cif_estimated'])->toBeTrue()
        ->and($summary['cost_per_gallon_with_cif'])->toBe(bcmul($summary['cost_per_gallon'], '1.8', 4))
        ->and($this->order->fresh()->only(['cif_percentage', 'cif_percentage_estimated']))
        ->toBe(['cif_percentage' => null, 'cif_percentage_estimated' => false]);
});

it('a cancelled order has no costs, on screen or in the preview', function () {
    $this->post(route('production-orders.cancel', $this->order), ['reason' => 'Ya no se va a producir'])
        ->assertSessionHasNoErrors();

    $this->get(route('production-orders.show', $this->order))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('order.cost_summary', null));

    $this->postJson(route('production-orders.preview-costs', $this->order), summaryTypedData($this))
        ->assertOk()
        ->assertExactJson(['ingredients' => [], 'products' => [], 'product_lines' => [], 'summary' => null]);
});

it('without «Ver costos» there is no summary on screen and the preview refuses', function (SystemRole $role) {
    $this->actingAs(userWithRole($role, ['email_verified_at' => now()]));

    $this->get(route('production-orders.show', $this->order))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.previewCosts', false)
            ->missing('order.cost_summary'));

    $this->postJson(route('production-orders.preview-costs', $this->order), summaryTypedData($this))->assertForbidden();

    expect(app(BuildProductionOrderExportDataAction::class)->execute($this->order))
        ->not->toHaveKey('cost_summary');
})->with([SystemRole::Production, SystemRole::Operator]);

it('compares each presentation with the price list of today and its real margin', function () {
    $noPrice = ProductVariant::create([
        'product_id' => $this->product->id,
        'code' => 'ESM-RES-CUN',
        'unit_of_measure_id' => $this->uom->id,
        'presentation_value' => 4,
        'presentation_label' => 'Cuñete',
        'is_active' => true,
    ]);
    $noPrice->forceFill(['current_price' => null])->saveQuietly();
    $noPricePlan = ProductionOrderPackagingPlan::create([
        'production_order_id' => $this->order->id,
        'product_variant_id' => $noPrice->id,
        'planned_units' => 1,
    ]);

    $comparison = app(ProductionOrderCostSummary::class)->forOrder($this->order->fresh())['comparison'];
    // Pendiente de completar: 30 × $5 entre 10 + 4 galones planeados = $10,7143 por galón, con CIF $15.
    $gallon = collect($comparison['presentations'])->firstWhere('id', $this->plan->id);
    $bucket = collect($comparison['presentations'])->firstWhere('id', $noPricePlan->id);

    expect($comparison['reference_cost_per_gallon'])->toBe('12.0000')
        ->and($comparison['reference_difference_percentage'])->toBe('-10.71')
        ->and($comparison['uses_reference_formula'])->toBe((bool) $this->formula->fresh()->is_price_reference)
        ->and($comparison['list_margin_percentage'])->toBe('30.00')
        ->and($gallon['unit_cost_with_cif'])->toBe('15.0000')
        ->and($gallon['list_price'])->toBe('20.0000')
        ->and($gallon['list_difference_percentage'])->toBe('-25.00')
        // Precio de venta 20 ÷ 0,7 = 28,5714 → 29 (pesos enteros): vendiendo a ese precio queda un 48,28 % de margen.
        ->and($gallon['real_margin_percentage'])->toBe('48.28')
        ->and($bucket['list_price'])->toBeNull()
        ->and($bucket['list_difference_percentage'])->toBeNull()
        ->and($bucket['real_margin_percentage'])->toBeNull();

    $this->product->update(['sales_margin' => null]);
    $withoutMargin = app(ProductionOrderCostSummary::class)->forOrder($this->order->fresh())['comparison'];

    expect($withoutMargin['list_margin_percentage'])->toBeNull()
        ->and(collect($withoutMargin['presentations'])->pluck('real_margin_percentage')->filter()->all())->toBe([]);
});

// El PDF y el Excel son la hoja de planta: el resumen se ve en el detalle de la OP, nunca impreso (2026-11-23).
it('the pdf and the excel never print the cost summary, even for whoever can see it', function () {
    summaryOrderWithAdjustmentAndRemnant($this);
    completeSummaryOrder($this)->assertSessionHasNoErrors();

    $payload = app(BuildProductionOrderExportDataAction::class)->execute($this->order->fresh());
    $pdfHtml = view('pdf.production-order', ['order' => $payload, 'logoBase64' => null, 'generatedAt' => '-'])->render();
    $excelHtml = view('excel.production-order', ['order' => $payload])->render();

    expect(shownCostSummary($this)['bulk']['total'])->not->toBeNull()
        ->and($payload)->not->toHaveKey('cost_summary')
        ->and($pdfHtml)->not->toContain('COSTO TOTAL GRANEL')->not->toContain('$192')
        ->and($excelHtml)->not->toContain('COSTO')->not->toContain('192.00');
});

it('the completed entry is the unit cost of each presentation, with and without CIF', function () {
    completeSummaryOrder($this)->assertSessionHasNoErrors();

    $entry = FinishedInventoryMovement::query()
        ->where('production_order_id', $this->order->id)
        ->where('type', InventoryMovementType::Entry)
        ->where('reason', FinishedInventoryMovementReason::Production)
        ->sole();
    $batch = FinishedProductBatch::query()->where('production_order_id', $this->order->id)->sole();

    expect(shownCostSummary($this)['packaging'])->toBe([[
        'id' => $this->plan->id,
        'units' => '10',
        'unit_cost' => $entry->cost_price,
        'unit_cost_with_cif' => $batch->transfer_unit_cost,
        'total_cost' => bcmul('10', $entry->cost_price, 4),
    ]]);
});
