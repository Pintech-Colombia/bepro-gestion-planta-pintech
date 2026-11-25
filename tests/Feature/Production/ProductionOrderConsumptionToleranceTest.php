<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\ProductionOrderStatus;
use App\Enums\SystemRole;
use App\Models\Alert;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderConsumptionReason;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderLineAdjustment;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Production\ProductionOrderConsumptionCheck;
use App\Services\Production\ProductionOrderCostSummary;
use App\Support\NumberFormat;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/*
 * Tolerancia de consumo y alerta de sobreconsumo. La orden de estas pruebas planea S-7 en dos pasos (30 + 30 kg) y S-11
 * en uno (10 kg), las dos a $1.000/kg; envasa 20 galones. Con la tolerancia de 10 %, S-7 se pasa por encima de 66 kg y
 * S-11 por encima de 11.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(RolePermissionSeeder::class);
    config(['production.consumption_tolerance_percentage' => '10.00']);

    $this->admin = User::factory()->create([
        'email_verified_at' => now(),
        'job_title' => 'Jefe de Producción',
        'signature_path' => 'signatures/test.png',
    ]);
    $this->admin->assignRole(SystemRole::Admin->value);

    $this->operator = User::factory()->create(['email_verified_at' => now()]);
    $this->operator->assignRole(SystemRole::Operator->value);

    $category = ProductCategory::create(['name' => 'Tolerancia']);
    $this->kg = UnitOfMeasure::create(['code' => 'kg-tol', 'name' => 'Kilogramo', 'symbol' => 'kg']);
    $this->gallon = UnitOfMeasure::create(['code' => 'gal-tol', 'name' => 'Galón', 'symbol' => 'gal']);
    $this->warehouse = Warehouse::create(['name' => 'Planta Tolerancia', 'city' => 'Cali', 'type' => 'factory', 'is_active' => true]);

    $this->s7 = toleranceMaterial($this, 'S-7', $this->kg);
    $this->s11 = toleranceMaterial($this, 'S-11', $this->kg);

    $this->product = Product::create([
        'name' => 'Gris claro tolerancia',
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->gallon->id,
        'current_cost' => 3500,
        'cif_percentage' => 15,
        'current_price' => 5000,
        'sales_margin' => 40,
        'price_threshold' => 5,
    ]);

    $this->formula = Formula::create([
        'product_id' => $this->product->id,
        'version' => 1,
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);
    foreach ([[1, $this->s7], [2, $this->s11], [3, $this->s7]] as [$step, $material]) {
        FormulaDetail::create([
            'formula_id' => $this->formula->id,
            'raw_material_id' => $material->id,
            'quantity' => 1,
            'unit_of_measure_id' => $this->kg->id,
            'step_order' => $step,
        ]);
    }

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'code' => 'GRIS-TOL-GAL',
        'unit_of_measure_id' => $this->gallon->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
        'current_price' => 5000,
        'is_active' => true,
    ]);

    $this->order = ProductionOrder::create([
        'order_number' => 'OP-TOL-'.substr(uniqid(), -6),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $this->product->id,
        'formula_id' => $this->formula->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 20,
        'status' => ProductionOrderStatus::InProgress->value,
        'planned_date' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->s7Step1 = toleranceLine($this, $this->s7, 1, '30');
    $this->s11Step2 = toleranceLine($this, $this->s11, 2, '10');
    $this->s7Step3 = toleranceLine($this, $this->s7, 3, '30');

    $this->plan = ProductionOrderPackagingPlan::create([
        'production_order_id' => $this->order->id,
        'product_variant_id' => $this->variant->id,
        'planned_units' => 20,
    ]);
});

function toleranceMaterial(object $test, string $code, UnitOfMeasure $unit, string $price = '1000'): RawMaterial
{
    $material = RawMaterial::create([
        'code' => $code,
        'unit_of_measure_id' => $unit->id,
        'current_price' => $price,
    ]);

    InventoryBatch::create([
        'raw_material_id' => $material->id,
        'warehouse_id' => $test->warehouse->id,
        'initial_quantity' => 5000,
        'remaining_quantity' => 5000,
        'unit_price' => $price,
        'entry_date' => now()->subDay(),
    ]);

    return $material;
}

function toleranceLine(object $test, RawMaterial $material, int $step, string $planned): ProductionOrderDetail
{
    return ProductionOrderDetail::create([
        'production_order_id' => $test->order->id,
        'raw_material_id' => $material->id,
        'step_order' => $step,
        'planned_quantity' => $planned,
        'unit_cost' => 1000,
        'total_cost' => bcmul($planned, '1000', 4),
    ]);
}

/**
 * Lo que entrega la planta: lo real de cada línea (S-7 paso 1, S-11, S-7 paso 3) y 20 galones envasados.
 *
 * @param  array<int, string>  $reasons  id de la materia prima => motivo
 * @return array<string, mixed>
 */
function tolerancePayload(object $test, string $s7Step1, string $s11, string $s7Step3, array $reasons = []): array
{
    return [
        'ingredients' => [
            ['id' => $test->s7Step1->id, 'actual_quantity' => $s7Step1],
            ['id' => $test->s11Step2->id, 'actual_quantity' => $s11],
            ['id' => $test->s7Step3->id, 'actual_quantity' => $s7Step3],
        ],
        'packaging' => [['id' => $test->plan->id, 'actual_units' => 20]],
        'density_kg_per_gallon' => 5,
        'quality_responsible_user_id' => $test->admin->id,
        'responsible_name' => 'Operario',
        'line_adjustment_ids' => $test->order->lineAdjustments()->pluck('id')->all(),
        'remnant_consumption_ids' => [],
        'overconsumption_reasons' => $reasons,
    ];
}

/** Un 403 (la orden no estaba en curso) no pasa por un envío sin errores. */
function toleranceSubmit(object $test, array $payload): TestResponse
{
    return $test->actingAs($test->operator)->post(route('production-orders.submit-for-review', $test->order), $payload)
        ->assertRedirect();
}

function toleranceComplete(object $test, array $payload): TestResponse
{
    return $test->actingAs($test->admin)->post(route('production-orders.complete', $test->order), $payload)
        ->assertRedirect();
}

function toleranceAdjustment(object $test, RawMaterial $material, string $quantity): ProductionOrderLineAdjustment
{
    return ProductionOrderLineAdjustment::create([
        'production_order_id' => $test->order->id,
        'raw_material_id' => $material->id,
        'quantity' => $quantity,
        'reason' => 'Corrección de viscosidad',
        'created_by' => $test->admin->id,
    ]);
}

/**
 * La fila de la materia prima con lo real de cada línea (id de la línea => cantidad).
 *
 * @param  array<int, string>  $actualByLine
 * @return array<string, mixed>|null
 */
function toleranceRow(object $test, RawMaterial $material, array $actualByLine): ?array
{
    $rows = app(ProductionOrderConsumptionCheck::class)->evaluate($test->order->fresh(), $actualByLine);

    return collect($rows)->firstWhere('raw_material_id', $material->id);
}

/** @return array<string, mixed> */
function toleranceShownOrder(object $test, User $viewer): array
{
    $order = null;

    $test->actingAs($viewer)->get(route('production-orders.show', $test->order))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$order) {
            $order = $page->toArray()['props']['order'];
        });

    return $order;
}

// ─── 1. La regla (ProductionOrderConsumptionCheck) ─────────────────────────────────────────────────────────────────

it('compares each raw material over all its steps: 60 in the first step and 0 in the second is not over', function () {
    $row = toleranceRow($this, $this->s7, [$this->s7Step1->id => '60', $this->s7Step3->id => '0']);

    expect($row['planned'])->toBe('60.0000')
        ->and($row['consumed'])->toBe('60.0000')
        ->and($row['limit'])->toBe('66.0000')
        ->and($row['exceeds'])->toBeFalse()
        ->and($row['needs_reason'])->toBeFalse();
});

it('60 plus 20 is over with 10 % and asks for the reason', function () {
    $row = toleranceRow($this, $this->s7, [$this->s7Step1->id => '60', $this->s7Step3->id => '20']);

    expect($row['consumed'])->toBe('80.0000')
        ->and($row['excess_percentage'])->toBe('33.33')
        ->and($row['tolerance_percentage'])->toBe('10.00')
        ->and($row['exceeds'])->toBeTrue()
        ->and($row['needs_reason'])->toBeTrue();
});

it('exactly at the limit is not over; the smallest amount above it is', function () {
    $atLimit = toleranceRow($this, $this->s7, [$this->s7Step1->id => '36', $this->s7Step3->id => '30']);
    $above = toleranceRow($this, $this->s7, [$this->s7Step1->id => '36.0001', $this->s7Step3->id => '30']);

    expect($atLimit['consumed'])->toBe('66.0000')
        ->and($atLimit['exceeds'])->toBeFalse()
        ->and($above['exceeds'])->toBeTrue()
        ->and($above['needs_reason'])->toBeTrue();
});

it('does not evaluate a raw material without a plan that only came in through a line adjustment', function () {
    $extra = toleranceMaterial($this, 'S-99', $this->kg);
    toleranceAdjustment($this, $extra, '50');

    expect(toleranceRow($this, $extra, []))->toBeNull();
});

it('an excess that only comes from line adjustments is over but asks for no reason', function () {
    toleranceAdjustment($this, $this->s7, '10');

    $row = toleranceRow($this, $this->s7, [$this->s7Step1->id => '30', $this->s7Step3->id => '30']);

    expect($row['consumed'])->toBe('70.0000')
        ->and($row['consumed_without_adjustments'])->toBe('60.0000')
        ->and($row['exceeds'])->toBeTrue()
        ->and($row['needs_reason'])->toBeFalse();
});

it('compares in the unit of the raw material, also when it is not kilograms', function () {
    $gram = UnitOfMeasure::create(['code' => 'g-tol', 'name' => 'Gramo', 'symbol' => 'g']);
    $tint = toleranceMaterial($this, 'TINTE-G', $gram, '2');
    $line = toleranceLine($this, $tint, 4, '500');

    $row = toleranceRow($this, $tint, [$line->id => '600']);

    expect($row['unit'])->toBe('g')
        ->and($row['planned'])->toBe('500.0000')
        ->and($row['excess_percentage'])->toBe('20.00')
        ->and($row['exceeds'])->toBeTrue()
        ->and(app(ProductionOrderConsumptionCheck::class)->summary($row))->toBe('TINTE-G: 600 g de 500 planeados (+20%)');
});

// ─── 2. Enviar a revisión ─────────────────────────────────────────────────────────────────────────────────────────

it('submitting for review without the reason answers 422 with the key of the raw material', function () {
    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20'))
        ->assertSessionHasErrors("overconsumption_reasons.{$this->s7->id}");

    expect(session('errors')->first("overconsumption_reasons.{$this->s7->id}"))
        ->toBe('S-7: 80 kg de 60 planeados (+33,3%). Se pasó más del 10% de lo planeado: escribe el motivo.')
        ->and(session('errors')->has("overconsumption_reasons.{$this->s11->id}"))->toBeFalse()
        ->and($this->order->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and(ProductionOrderConsumptionReason::query()->count())->toBe(0);
});

it('submitting for review with the reason saves it, and a rejection keeps it to be edited', function () {
    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Se ajustó la viscosidad']))
        ->assertSessionHasNoErrors();

    $reason = ProductionOrderConsumptionReason::query()->sole();
    expect($reason->raw_material_id)->toBe($this->s7->id)
        ->and($reason->reason)->toBe('Se ajustó la viscosidad')
        ->and($reason->created_by)->toBe($this->operator->id)
        ->and($this->order->fresh()->status)->toBe(ProductionOrderStatus::PendingReview);

    $this->actingAs($this->admin)->post(route('production-orders.reject-review', $this->order), ['reason' => 'Revisar S-7'])
        ->assertSessionHasNoErrors();

    expect(ProductionOrderConsumptionReason::query()->sole()->reason)->toBe('Se ajustó la viscosidad')
        ->and(toleranceShownOrder($this, $this->operator)['consumption_reasons'])->toBe([
            ['raw_material_id' => $this->s7->id, 'code' => 'S-7', 'reason' => 'Se ajustó la viscosidad'],
        ]);

    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad alta del lote de resina']))
        ->assertSessionHasNoErrors();

    expect(ProductionOrderConsumptionReason::query()->sole()->reason)->toBe('Viscosidad alta del lote de resina');
});

it('a raw material that stops being over loses its reason when saved again', function () {
    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Se ajustó la viscosidad']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('production-orders.reject-review', $this->order), ['reason' => 'Era 0 en el paso 3'])
        ->assertSessionHasNoErrors();

    toleranceSubmit($this, tolerancePayload($this, '60', '10', '0', [$this->s7->id => 'Se ajustó la viscosidad']))
        ->assertSessionHasNoErrors();

    expect(ProductionOrderConsumptionReason::query()->count())->toBe(0);
});

// ─── 3. Completar ─────────────────────────────────────────────────────────────────────────────────────────────────

it('completing directly asks for the reason, then saves it with the tolerance used', function () {
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20'))
        ->assertSessionHasErrors("overconsumption_reasons.{$this->s7->id}");
    expect($this->order->fresh()->status)->toBe(ProductionOrderStatus::InProgress);

    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Lote de resina más espeso']))
        ->assertSessionHasNoErrors();

    $order = $this->order->fresh();
    expect($order->status)->toBe(ProductionOrderStatus::Completed)
        ->and($order->consumption_tolerance_percentage)->toBe('10.00')
        ->and(ProductionOrderConsumptionReason::query()->sole()->created_by)->toBe($this->admin->id);
});

it('completing an order without any excess also keeps the tolerance used', function () {
    toleranceComplete($this, tolerancePayload($this, '30', '10', '30'))->assertSessionHasNoErrors();

    expect($this->order->fresh()->consumption_tolerance_percentage)->toBe('10.00')
        ->and(Alert::query()->where('type', AlertType::Sobreconsumo)->exists())->toBeFalse();
});

it('whoever approves from review can correct the reason, and one that stops being over is deleted', function () {
    toleranceSubmit($this, tolerancePayload($this, '60', '15', '20', [
        $this->s7->id => 'Viscosidad',
        $this->s11->id => 'Derrame en el paso 2',
    ]))->assertSessionHasNoErrors();
    expect(ProductionOrderConsumptionReason::query()->count())->toBe(2);

    // Quien aprueba corrige S-11 a lo que de verdad se usó (10) y precisa el motivo de S-7.
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [
        $this->s7->id => 'Viscosidad alta: lote de resina más espeso',
        $this->s11->id => 'Derrame en el paso 2',
    ]))->assertSessionHasNoErrors();

    $reason = ProductionOrderConsumptionReason::query()->sole();
    expect($reason->raw_material_id)->toBe($this->s7->id)
        ->and($reason->reason)->toBe('Viscosidad alta: lote de resina más espeso')
        ->and($this->order->fresh()->status)->toBe(ProductionOrderStatus::Completed);
});

it('changing the tolerance changes the evaluation of new orders, not of completed ones', function () {
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    config(['production.consumption_tolerance_percentage' => '50.00']);
    $check = app(ProductionOrderConsumptionCheck::class);

    // La completada sigue con el 10 % que se evaluó: S-7 se pasa.
    expect(collect($check->forOrder($this->order->fresh()))->pluck('code')->all())->toBe(['S-7'])
        // Con el 50 %, los mismos 80 kg de una orden abierta ya no se pasan.
        ->and(collect($check->evaluate($this->order->fresh()))->firstWhere('code', 'S-7')['exceeds'])->toBeFalse();
});

// ─── 4. La alerta ─────────────────────────────────────────────────────────────────────────────────────────────────

it('completing creates one alert for the order with every raw material over, adjustments included', function () {
    toleranceAdjustment($this, $this->s11, '2');

    toleranceComplete($this, tolerancePayload($this, '60', '10', '10', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    $alert = Alert::query()->where('type', AlertType::Sobreconsumo)->sole();
    $order = $this->order->fresh();
    $presentation = app(ProductionOrderCostSummary::class)->forOrder($order)['comparison']['presentations'][0];

    // S-7: 70 de 60 (+16,7 %), 10 kg × $1.000. S-11: 12 de 10 (+20 %), 2 kg × $1.000 (el ajuste cuenta).
    expect($alert->production_order_id)->toBe($order->id)
        ->and($alert->raw_material_id)->toBeNull()
        ->and($alert->severity)->toBe(AlertSeverity::Alta)
        ->and($alert->message)->toBe(sprintf(
            '%s · %s: S-7 +16,7%% (70 de 60 kg, +$10.000); S-11 +20%% (12 de 10 kg, +$2.000). Margen real Galón %s (lista 40%%).',
            $order->order_number,
            $order->productDisplayName(),
            NumberFormat::percent($presentation['real_margin_percentage']),
        ));
});

it('the alert is medium when no raw material passes twice the tolerance', function () {
    toleranceComplete($this, tolerancePayload($this, '36', '10', '33', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    $alert = Alert::query()->where('type', AlertType::Sobreconsumo)->sole();

    expect($alert->severity)->toBe(AlertSeverity::Media)
        ->and($alert->message)->toContain('S-7 +15% (69 de 60 kg, +$9.000)')
        ->and($alert->message)->not->toContain('S-11');
});

it('only who can see costs sees the overconsumption alert', function () {
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    $production = User::factory()->create();
    $production->assignRole(SystemRole::Production->value);

    expect(Alert::query()->visibleTo($this->admin)->where('type', AlertType::Sobreconsumo)->count())->toBe(1)
        ->and(Alert::query()->visibleTo($this->operator)->where('type', AlertType::Sobreconsumo)->count())->toBe(0)
        ->and($production->can('costs.view'))->toBeFalse()
        ->and(Alert::query()->visibleTo($production)->where('type', AlertType::Sobreconsumo)->count())->toBe(0);
});

it('the alerts list links the overconsumption alert to its order', function () {
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alerts.data.0.type', AlertType::Sobreconsumo->value)
            ->where('alerts.data.0.production_order.id', $this->order->id)
            ->where('can.viewProductionOrders', true));
});

it('reproduces OP 207: S-7 planned 38.65 kg and 55.6 used asks for the reason and creates the alert', function () {
    $this->s7Step1->update(['planned_quantity' => '38.65', 'total_cost' => '38650']);
    $this->s7Step3->update(['planned_quantity' => '0', 'total_cost' => '0']);

    toleranceSubmit($this, tolerancePayload($this, '55.6', '10', '0'))
        ->assertSessionHasErrors("overconsumption_reasons.{$this->s7->id}");
    expect(session('errors')->first("overconsumption_reasons.{$this->s7->id}"))
        ->toStartWith('S-7: 55,6 kg de 38,65 planeados (+43,9%)');

    toleranceSubmit($this, tolerancePayload($this, '55.6', '10', '0', [$this->s7->id => 'Viscosidad fuera de rango']))
        ->assertSessionHasNoErrors();
    toleranceComplete($this, tolerancePayload($this, '55.6', '10', '0', [$this->s7->id => 'Viscosidad fuera de rango']))
        ->assertSessionHasNoErrors();

    $alert = Alert::query()->where('type', AlertType::Sobreconsumo)->sole();

    // 16,95 kg × $1.000 de más; 43,86 % pasa el doble del 10 %.
    expect($alert->severity)->toBe(AlertSeverity::Alta)
        ->and($alert->message)->toContain('S-7 +43,9% (55,6 de 38,65 kg, +$16.950)');
});

// ─── 5. El detalle de la OP ───────────────────────────────────────────────────────────────────────────────────────

it('the order detail marks what was over with its reason, with money only for who sees costs', function () {
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    $forAdmin = toleranceShownOrder($this, $this->admin);
    $forOperator = toleranceShownOrder($this, $this->operator);

    expect($forAdmin['consumption_tolerance_percentage'])->toBe('10.00')
        ->and($forAdmin['overconsumption'])->toHaveCount(1)
        ->and($forAdmin['overconsumption'][0])->toMatchArray([
            'raw_material_id' => $this->s7->id,
            'code' => 'S-7',
            'unit' => 'kg',
            'planned' => '60.0000',
            'consumed' => '80.0000',
            'excess_percentage' => '33.33',
            'needs_reason' => true,
            'reason' => 'Viscosidad',
            'extra_cost' => '20000.0000',
        ])
        ->and($forOperator['overconsumption'][0]['reason'])->toBe('Viscosidad')
        ->and($forOperator['overconsumption'][0])->not->toHaveKey('extra_cost')
        ->and($forOperator)->not->toHaveKey('cost_summary');
});

it('an order in review marks what was over with the tolerance in force', function () {
    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    $shown = toleranceShownOrder($this, $this->admin);

    expect($shown['overconsumption'])->toHaveCount(1)
        ->and($shown['overconsumption'][0]['tolerance_percentage'])->toBe('10.00')
        ->and($shown['overconsumption'][0]['extra_cost'])->toBeNull();
});

it('an old completed order without a tolerance marks nothing', function () {
    $this->s7Step1->update(['actual_quantity' => '60']);
    $this->s7Step3->update(['actual_quantity' => '20']);
    $this->s11Step2->update(['actual_quantity' => '10']);
    $this->order->update(['status' => ProductionOrderStatus::Completed, 'consumption_tolerance_percentage' => null]);

    expect(toleranceShownOrder($this, $this->admin)['overconsumption'])->toBe([]);
});

// ─── 6. Auditoría ─────────────────────────────────────────────────────────────────────────────────────────────────

it('logs the reasons when they are written, corrected and deleted', function () {
    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('production-orders.reject-review', $this->order), ['reason' => 'Revisar el consumo de S-7'])
        ->assertSessionHasNoErrors();
    toleranceSubmit($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad alta']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('production-orders.reject-review', $this->order), ['reason' => 'Revisar el consumo de S-7'])
        ->assertSessionHasNoErrors();
    toleranceSubmit($this, tolerancePayload($this, '30', '10', '30'))->assertSessionHasNoErrors();
    expect($this->order->fresh()->status)->toBe(ProductionOrderStatus::PendingReview);

    $logs = Activity::query()
        ->where('subject_type', (new ProductionOrderConsumptionReason)->getMorphClass())
        ->orderBy('id')
        ->get();

    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($logs->first()->description)->toBe("Motivo de sobreconsumo \"{$this->order->order_number} · S-7\" creado")
        ->and($logs->first()->log_name)->toBe('ordenes_produccion')
        ->and($logs[1]->properties['attributes']['reason'])->toBe('Viscosidad alta')
        ->and($logs[1]->causer_id)->toBe($this->operator->id);
});

// ─── 7. Tolerancia por familia ────────────────────────────────────────────────────────────────────────────────────

/** Pasa la materia prima a una categoría de una familia con el % dado (null = la familia usa el general). */
function toleranceFamily(RawMaterial $material, string $name, ?string $percentage): RawMaterialFamily
{
    $family = RawMaterialFamily::factory()->create(['name' => $name, 'consumption_tolerance_percentage' => $percentage]);
    $category = RawMaterialCategory::factory()->create(['family_id' => $family->id]);
    $material->update(['category_id' => $category->id]);

    return $family;
}

it('each raw material is measured with the tolerance of its family, and one without family with the general', function () {
    toleranceFamily($this->s7, 'RESINA', '40.00');

    // S-7: 80 de 60 (+33,3 %), dentro del 40 % de Resina. S-11: 12 de 10 (+20 %), fuera del 10 % general.
    $rows = collect(app(ProductionOrderConsumptionCheck::class)->evaluate($this->order->fresh(), [
        $this->s7Step1->id => '60', $this->s11Step2->id => '12', $this->s7Step3->id => '20',
    ]))->keyBy('code');

    expect($rows['S-7']['tolerance_percentage'])->toBe('40.00')
        ->and($rows['S-7']['exceeds'])->toBeFalse()
        ->and($rows['S-11']['tolerance_percentage'])->toBe('10.00')
        ->and($rows['S-11']['exceeds'])->toBeTrue();
});

it('a family without its own tolerance uses the general', function () {
    toleranceFamily($this->s7, 'PIGMENTO', null);

    expect(app(ProductionOrderConsumptionCheck::class)->tolerancesByMaterial([$this->s7->id]))->toBe([$this->s7->id => '10.00']);
});

it('completing keeps the tolerance of each line, and a later change in the family does not change the completed order', function () {
    $resina = toleranceFamily($this->s7, 'RESINA', '40.00');

    toleranceComplete($this, tolerancePayload($this, '60', '10', '20'))->assertSessionHasNoErrors();

    $order = $this->order->fresh();
    expect($this->s7Step1->fresh()->consumption_tolerance_percentage)->toBe('40.00')
        ->and($this->s7Step3->fresh()->consumption_tolerance_percentage)->toBe('40.00')
        ->and($this->s11Step2->fresh()->consumption_tolerance_percentage)->toBe('10.00')
        // La orden guarda el general: el del producto terminado y el de las completadas antes de esto.
        ->and($order->consumption_tolerance_percentage)->toBe('10.00');

    $resina->update(['consumption_tolerance_percentage' => '5.00']);

    // Con el 40 % que se evaluó, S-7 sigue sin pasarse aunque Resina ahora tenga 5 %.
    expect(app(ProductionOrderConsumptionCheck::class)->forOrder($order->fresh()))->toBe([]);
});

it('an order completed before the family tolerance uses the tolerance it kept for every line', function () {
    toleranceFamily($this->s7, 'RESINA', '40.00');
    $this->s7Step1->update(['actual_quantity' => '60']);
    $this->s7Step3->update(['actual_quantity' => '20']);
    $this->s11Step2->update(['actual_quantity' => '10']);
    $this->order->update(['status' => ProductionOrderStatus::Completed, 'consumption_tolerance_percentage' => '10.00']);

    // Sus líneas no guardaron %: S-7 se mide con el 10 % de la orden, no con el 40 % de hoy.
    expect(collect(app(ProductionOrderConsumptionCheck::class)->forOrder($this->order->fresh()))->pluck('code')->all())
        ->toBe(['S-7']);
});

it('the alert doubles the tolerance of each raw material, not a shared one', function () {
    toleranceFamily($this->s7, 'RESINA', '20.00');

    // S-7: 80 de 60 (+33,3 %), fuera del 20 % de Resina pero sin llegar a su doble (40 %).
    toleranceComplete($this, tolerancePayload($this, '60', '10', '20', [$this->s7->id => 'Viscosidad']))
        ->assertSessionHasNoErrors();

    expect(Alert::query()->where('type', AlertType::Sobreconsumo)->sole()->severity)->toBe(AlertSeverity::Media);
});

it('the evaluation uses the tolerances it is given, the same ones completing stores in the lines', function () {
    toleranceFamily($this->s7, 'RESINA', '40.00');

    // Completar calcula el % una vez y lo pasa: aunque la familia diga 40 %, se evalúa con lo que se pasó.
    $row = collect(app(ProductionOrderConsumptionCheck::class)->evaluate(
        $this->order->fresh(),
        [$this->s7Step1->id => '60', $this->s7Step3->id => '20'],
        null,
        [$this->s7->id => '10.00', $this->s11->id => '10.00'],
    ))->firstWhere('code', 'S-7');

    expect($row['tolerance_percentage'])->toBe('10.00')
        ->and($row['exceeds'])->toBeTrue();
});
