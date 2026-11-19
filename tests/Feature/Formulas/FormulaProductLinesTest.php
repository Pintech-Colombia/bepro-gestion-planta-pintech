<?php

declare(strict_types=1);

use App\Enums\FormulaLineCostingBasis;
use App\Enums\Permission;
use App\Enums\ProductionOrderStatus;
use App\Enums\SystemRole;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeactivationGuardService;
use App\Services\Pricing\ProductLineTheoreticalCost;
use App\Services\ProductionCostRecalculationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * PR D de «Plan — PT consumible»: un producto como ingrediente de una fórmula, su costo teórico por kilo (P13), «Se
 * costea como» (P14), los ciclos (D1) y la cadena de recálculo. Ejemplo del alcance: esmalte blanco con 33.750 de granel
 * por galón, CIF 20 % y 4,5 kg/gal → 9.000 por kg a granel; el galón con envase y etiqueta (42.750) → 11.400 por kg.
 */
beforeEach(function () {
    if (Role::count() === 0) {
        $this->seed(RolePermissionSeeder::class);
    }

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(SystemRole::Admin->value);
    $this->actingAs($this->admin);

    $this->kg = UnitOfMeasure::create(['code' => 'kg', 'name' => 'Kilogramo', 'symbol' => 'kg', 'to_kg_conversion' => 1]);
    $this->gram = UnitOfMeasure::create(['code' => 'g', 'name' => 'Gramo', 'symbol' => 'g', 'to_kg_conversion' => '0.001']);
    $this->liter = UnitOfMeasure::create(['code' => 'l', 'name' => 'Litro', 'symbol' => 'l', 'to_liter_conversion' => 1]);

    $category = RawMaterialCategory::create(['code' => 'RM-PT', 'name' => 'Químicos', 'is_active' => true]);
    $material = fn (string $code, string $price) => RawMaterial::create([
        'code' => $code,
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->kg->id,
        'current_price' => $price,
        'minimum_stock' => 0,
        'alert_days_before_expiry' => 30,
        'is_active' => true,
    ]);
    $this->whiteBase = $material('MP-BLANCO', '7500');
    $this->paste = $material('MP-PASTA', '10000');

    $productCategory = ProductCategory::create(['name' => 'Esmaltes']);
    $product = fn (string $name, string $cif, ?string $density = null) => Product::create([
        'name' => $name,
        'category_id' => $productCategory->id,
        'unit_of_measure_id' => $this->liter->id,
        'cif_percentage' => $cif,
        'price_threshold' => 0,
        'reference_density_kg_per_gallon' => $density,
        'is_active' => true,
    ]);
    $this->white = $product('Esmalte blanco', '20', '4.5');
    $this->ral = $product('RAL 7016', '25');
    $this->third = $product('Gris oscuro', '10');

    // 4,5 kg de MP a 7.500 = 33.750 de granel por galón (la primera fórmula es la de referencia y se costea al crearla).
    formulaWith($this->white, [['raw_material_id' => $this->whiteBase->id, 'quantity' => '4.5', 'unit_of_measure_id' => $this->kg->id]]);

    $this->whiteGallon = ProductVariant::create([
        'product_id' => $this->white->id,
        'code' => 'BL-GAL',
        'unit_of_measure_id' => $this->liter->id,
        'presentation_label' => 'Galón',
        'presentation_value' => 1,
        'is_active' => true,
    ]);
    // Granel 33.750 + envase 8.000 + etiqueta 1.000.
    DB::table('product_variants')->where('id', $this->whiteGallon->id)->update(['current_cost' => '42750']);
});

/**
 * @param  list<array<string, mixed>>  $details
 */
function productLinesPayload(array $details): array
{
    return array_map(fn (array $detail): array => [
        'raw_material_id' => null,
        'product_id' => null,
        'costing_basis' => null,
        'costing_variant_id' => null,
        ...$detail,
    ], $details);
}

/**
 * @param  list<array<string, mixed>>  $details
 */
function formulaWith(Product $product, array $details): Formula
{
    test()->post(route('formulas.store'), [
        'product_id' => $product->id,
        'details' => productLinesPayload($details),
    ])->assertSessionHasNoErrors();

    return Formula::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
}

/**
 * RAL 7016: 3 kg de blanco «a granel» + 2 kg de pasta (20.000).
 */
function ralWithWhite(string $costingBasis = 'bulk', ?int $variantId = null): Formula
{
    return formulaWith(test()->ral, [
        ['product_id' => test()->white->id, 'costing_basis' => $costingBasis, 'costing_variant_id' => $variantId, 'quantity' => '3', 'unit_of_measure_id' => test()->kg->id],
        ['raw_material_id' => test()->paste->id, 'quantity' => '2', 'unit_of_measure_id' => test()->kg->id],
    ]);
}

// --- Validación ---

it('rechaza una línea de producto sin forma de costeo, inactiva, de otro producto o en una unidad que no es de peso', function (array $line, string $errorKey) {
    if (($line['product_id'] ?? null) === 'inactive') {
        $line['product_id'] = Product::factory()->inactive()->create()->id;
    }

    $this->post(route('formulas.store'), [
        'product_id' => $this->ral->id,
        'details' => productLinesPayload([['quantity' => '3', 'unit_of_measure_id' => $this->kg->id, ...$line]]),
    ])->assertSessionHasErrors($errorKey);
})->with([
    'sin forma de costeo' => [fn () => ['product_id' => test()->white->id], 'details.0.costing_basis'],
    'presentación de otro producto' => [fn () => ['product_id' => test()->white->id, 'costing_basis' => 'presentation', 'costing_variant_id' => ProductVariant::factory()->create()->id], 'details.0.costing_basis'],
    'producto inactivo' => [fn () => ['product_id' => 'inactive', 'costing_basis' => 'bulk'], 'details.0.product_id'],
    'unidad de volumen' => [fn () => ['product_id' => test()->white->id, 'costing_basis' => 'bulk', 'unit_of_measure_id' => test()->liter->id], 'details.0.unit_of_measure_id'],
    'materia prima y producto a la vez' => [fn () => ['product_id' => test()->white->id, 'costing_basis' => 'bulk', 'raw_material_id' => test()->paste->id], 'details.0.raw_material_id'],
    'ni materia prima ni producto' => [fn () => [], 'details.0.raw_material_id'],
]);

it('no deja que un producto vaya en su propia fórmula', function () {
    $this->post(route('formulas.store'), [
        'product_id' => $this->white->id,
        'details' => productLinesPayload([['product_id' => $this->white->id, 'costing_basis' => 'bulk', 'quantity' => '1', 'unit_of_measure_id' => $this->kg->id]]),
    ])->assertSessionHasErrors(['details.0.product_id' => 'Un producto no puede ir en su propia fórmula.']);
});

it('guarda la línea de producto con su forma de costeo, en gramos también', function () {
    $formula = formulaWith($this->ral, [
        ['product_id' => $this->white->id, 'costing_basis' => 'presentation', 'costing_variant_id' => $this->whiteGallon->id, 'quantity' => '84', 'unit_of_measure_id' => $this->gram->id],
    ]);

    $line = $formula->details()->sole();

    expect($line->raw_material_id)->toBeNull()
        ->and($line->product_id)->toBe($this->white->id)
        ->and($line->costing_basis)->toBe(FormulaLineCostingBasis::Presentation)
        ->and($line->costing_variant_id)->toBe($this->whiteGallon->id);
});

it('la base rechaza una línea con materia prima y producto a la vez, o con presentación sin forma de costeo', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Solo PostgreSQL: la CHECK se agrega con ALTER TABLE.');
    }

    $formula = ralWithWhite();
    $line = $formula->details->firstWhere('product_id', $this->white->id);

    expect(fn () => DB::table('formula_details')->where('id', $line->id)->update(['raw_material_id' => $this->paste->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('formula_details')->where('id', $line->id)->update(['costing_basis' => null, 'costing_variant_id' => $this->whiteGallon->id]))
        ->toThrow(QueryException::class);
});

// --- Ciclos ---

it('rechaza el ciclo directo y el indirecto, con el camino en el mensaje', function () {
    ralWithWhite();
    formulaWith($this->third, [['product_id' => $this->ral->id, 'costing_basis' => 'bulk', 'quantity' => '1', 'unit_of_measure_id' => $this->kg->id]]);

    // Blanco → Gris oscuro → RAL 7016 → Blanco.
    $this->post(route('formulas.store'), [
        'product_id' => $this->white->id,
        'details' => productLinesPayload([['product_id' => $this->third->id, 'costing_basis' => 'bulk', 'quantity' => '1', 'unit_of_measure_id' => $this->kg->id]]),
    ])->assertSessionHasErrors(['details' => 'Se formaría un ciclo: ESMALTE BLANCO → GRIS OSCURO → RAL 7016 → ESMALTE BLANCO. Un producto no puede llevarse a sí mismo, ni a través de otro.']);

    // Directo: RAL 7016 ya lleva blanco.
    $this->post(route('formulas.store'), [
        'product_id' => $this->white->id,
        'details' => productLinesPayload([['product_id' => $this->ral->id, 'costing_basis' => 'bulk', 'quantity' => '1', 'unit_of_measure_id' => $this->kg->id]]),
    ])->assertSessionHasErrors('details');
});

it('al activar una fórmula vieja que formaría un ciclo, avisa y no la activa', function () {
    // Una fórmula inactiva del blanco que lleva RAL 7016 se guarda (no cuenta en la cadena)…
    $whiteWithRal = formulaWith($this->white, [['product_id' => $this->ral->id, 'costing_basis' => 'bulk', 'quantity' => '1', 'unit_of_measure_id' => $this->kg->id]]);
    $this->post(route('formulas.deactivate', $whiteWithRal))->assertSessionHasNoErrors();

    // …RAL 7016 lleva blanco…
    ralWithWhite();

    // …y activarla cerraría el ciclo.
    $this->post(route('formulas.activate', $whiteWithRal))
        ->assertInertiaFlash('error');

    expect($whiteWithRal->fresh()->is_active)->toBeFalse();
});

// --- Costo teórico por kilo (P13, P14) ---

it('costea el kilo a granel y con presentación con el ejemplo del alcance', function () {
    $bulk = ralWithWhite()->details->firstWhere('product_id', $this->white->id);
    $presentation = new FormulaDetail([
        'product_id' => $this->white->id,
        'costing_basis' => FormulaLineCostingBasis::Presentation,
        'costing_variant_id' => $this->whiteGallon->id,
    ]);
    $presentation->id = 999;

    $costs = app(ProductLineTheoreticalCost::class)->forLines([$bulk, $presentation]);

    expect($costs[$bulk->id])->toMatchArray(['cost_per_kg' => '9000.0000', 'bulk_per_kg' => '9000.0000', 'density' => '4.5000', 'density_source' => 'reference'])
        ->and($costs[999])->toMatchArray(['cost_per_kg' => '11400.0000', 'bulk_per_kg' => '9000.0000', 'packaging_per_kg' => '2400.0000']);
});

it('sin peso de referencia usa la densidad de la última OP completada, y sin ninguna dice el motivo', function () {
    $this->white->update(['reference_density_kg_per_gallon' => null]);
    $line = ralWithWhite()->details->firstWhere('product_id', $this->white->id);

    expect(app(ProductLineTheoreticalCost::class)->forLines([$line])[$line->id])
        ->toMatchArray(['cost_per_kg' => null, 'reason' => ProductLineTheoreticalCost::REASON_MISSING_DENSITY]);

    $order = ProductionOrder::factory()->create([
        'product_id' => $this->white->id,
        'created_by' => $this->admin->id,
        'status' => ProductionOrderStatus::Completed,
        'density_kg_per_gallon' => '4.0',
        'completion_date' => now(),
    ]);

    // 33.750 × 1,20 ÷ 4,0 = 10.125.
    expect(app(ProductLineTheoreticalCost::class)->forLines([$line->fresh()])[$line->id])
        ->toMatchArray(['cost_per_kg' => '10125.0000', 'density_source' => 'last_lot', 'density_order_number' => $order->order_number]);
});

it('una línea sin forma de costeo (carga inicial) no se puede costear y lo dice', function () {
    $formula = ralWithWhite();
    $line = $formula->details->firstWhere('product_id', $this->white->id);
    DB::table('formula_details')->where('id', $line->id)->update(['costing_basis' => null]);

    expect(app(ProductLineTheoreticalCost::class)->forLines([$line->fresh()])[$line->id]['reason'])
        ->toBe(ProductLineTheoreticalCost::REASON_MISSING_BASIS);
});

// --- Recálculo y cadena ---

it('costea la fórmula con el producto: 3 kg × 9.000 + 2 kg × 10.000 = 47.000 por galón', function () {
    ralWithWhite();

    expect($this->ral->fresh()->current_cost)->toBe('47000.0000');
});

it('con presentación suma el envase y la etiqueta: 3 kg × 11.400 + 20.000 = 54.200', function () {
    ralWithWhite('presentation', $this->whiteGallon->id);

    expect($this->ral->fresh()->current_cost)->toBe('54200.0000');
});

it('un cambio de precio de la MP del blanco llega a RAL 7016 y al que lleva RAL 7016 (cadena)', function () {
    ralWithWhite();
    $this->ral->update(['reference_density_kg_per_gallon' => '4.5']);
    formulaWith($this->third, [['product_id' => $this->ral->id, 'costing_basis' => 'bulk', 'quantity' => '1', 'unit_of_measure_id' => $this->kg->id]]);

    // Gris oscuro: 1 kg de RAL 7016 = 47.000 × 1,25 ÷ 4,5.
    expect($this->third->fresh()->current_cost)->toBe('13055.5556');

    $this->whiteBase->update(['current_price' => '10000']);
    app(ProductionCostRecalculationService::class)->recalculateForRawMaterial($this->whiteBase->id);

    // Blanco: 45.000 por galón → 12.000 por kg → RAL 7016: 3 × 12.000 + 20.000 = 56.000.
    expect($this->white->fresh()->current_cost)->toBe('45000.0000')
        ->and($this->ral->fresh()->current_cost)->toBe('56000.0000')
        // Tercer nivel: 56.000 × 1,25 ÷ 4,5.
        ->and($this->third->fresh()->current_cost)->toBe('15555.5556');
});

it('cambiar el peso de referencia del blanco recalcula RAL 7016', function () {
    ralWithWhite();

    $this->put(route('products.update', $this->white), [
        ...$this->white->only(['name', 'category_id', 'unit_of_measure_id', 'cif_percentage', 'price_threshold', 'is_active']),
        'reference_density_kg_per_gallon' => '5',
    ])->assertSessionHasNoErrors();

    // 33.750 × 1,20 ÷ 5 = 8.100 → 3 × 8.100 + 20.000 = 44.300.
    expect($this->ral->fresh()->current_cost)->toBe('44300.0000');
});

it('si una línea no se puede costear, el producto conserva su costo', function () {
    ralWithWhite();
    $this->white->update(['reference_density_kg_per_gallon' => null]);

    app(ProductionCostRecalculationService::class)->recalculateForProductOrLog($this->ral->id);

    expect($this->ral->fresh()->current_cost)->toBe('47000.0000');
});

// --- Bloqueos ---

it('no deja crear una OP con una fórmula que lleva productos (hasta el PR E)', function () {
    $formula = ralWithWhite();
    $warehouse = Warehouse::factory()->factory()->create();

    $this->post(route('production-orders.store'), [
        'product_id' => $this->ral->id,
        'formula_id' => $formula->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 10,
        'planned_date' => now()->addDay()->toDateString(),
        'packaging' => [],
    ])->assertSessionHasErrors('formula_id');

    expect(ProductionOrder::query()->where('formula_id', $formula->id)->exists())->toBeFalse();
});

it('no deja desactivar una presentación que una fórmula activa usa para costear', function () {
    ralWithWhite('presentation', $this->whiteGallon->id);

    expect(app(DeactivationGuardService::class)->variantBlocker($this->whiteGallon))->toContain('la usa para costear un ingrediente');
});

// --- Auditoría ---

it('la auditoría nombra el producto de la línea, y una MP y un producto con el mismo id no se emparejan', function () {
    $formula = ralWithWhite();
    Activity::query()->delete();

    // El mismo paso pasa de producto a materia prima: es un ingrediente distinto (borrado + creación).
    $this->put(route('formulas.update', $formula), [
        'details' => productLinesPayload([
            ['raw_material_id' => $this->whiteBase->id, 'quantity' => '3', 'unit_of_measure_id' => $this->kg->id],
            ['raw_material_id' => $this->paste->id, 'quantity' => '2', 'unit_of_measure_id' => $this->kg->id],
        ]),
    ])->assertSessionHasNoErrors();

    $lines = Activity::query()->where('subject_type', FormulaDetail::class)->orderBy('id')->get();

    expect($lines->map(fn (Activity $activity) => [$activity->event, $activity->description])->all())->toBe([
        ['deleted', 'Línea de fórmula "RAL 7016 v1 · paso 1 · ESMALTE BLANCO" eliminada'],
        ['created', 'Línea de fórmula "RAL 7016 v1 · paso 1 · MP-BLANCO" creada'],
    ]);
});

// --- Ficha ---

it('la ficha envía el costo por kilo de las líneas de producto solo con costs.view', function (bool $withCosts) {
    $formula = ralWithWhite();
    $user = $withCosts ? $this->admin : userWithRole(SystemRole::Operator);

    if (! $withCosts) {
        $user->givePermissionTo(Permission::FormulasView->value);
    }

    $this->actingAs($user)
        ->get(route('formulas.show', $formula))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.viewCosts', $withCosts)
            ->where('formula.details.0.product.name', 'ESMALTE BLANCO')
            ->where('formula.details.0.costing_basis', 'bulk')
            ->where('productLineCosts', fn ($costs) => $withCosts ? count($costs) === 1 : count($costs) === 0)
        );
})->with(['con costos' => [true], 'sin costos' => [false]]);

it('la ficha costea una línea «con presentación» aunque la pantalla cargue la presentación con pocas columnas', function () {
    $formula = ralWithWhite('presentation', $this->whiteGallon->id);
    $lineId = $formula->details->firstWhere('product_id', $this->white->id)->id;

    $this->get(route('formulas.show', $formula))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('formula.details.0.costing_label', 'Galón')
            ->where("productLineCosts.{$lineId}.cost_per_kg", '11400.0000')
            ->where("productLineCosts.{$lineId}.reason", null)
        );
});

it('una presentación sin valor cuenta como 1 galón, como en la lista de precios', function () {
    DB::table('product_variants')->where('id', $this->whiteGallon->id)->update(['presentation_value' => null]);
    $line = new FormulaDetail([
        'product_id' => $this->white->id,
        'costing_basis' => FormulaLineCostingBasis::Presentation,
        'costing_variant_id' => $this->whiteGallon->id,
    ]);
    $line->id = 998;

    expect(app(ProductLineTheoreticalCost::class)->forLines([$line])[998]['cost_per_kg'])->toBe('11400.0000');
});

it('no encola la cadena para un producto que no es ingrediente de nadie', function () {
    Queue::fake();

    app(ProductionCostRecalculationService::class)->dispatchDependentsOf($this->paste->id + 1000);
    app(ProductionCostRecalculationService::class)->dispatchDependentsOf($this->third->id);

    Queue::assertNothingPushed();
});
