<?php

declare(strict_types=1);

use App\Actions\Formulas\DeleteFormulaAction;
use App\Enums\SystemRole;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionCost;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (Role::count() === 0) {
        $this->seed(RolePermissionSeeder::class);
    }

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(SystemRole::Admin->value);
    $this->actingAs($this->admin);

    $this->unit = UnitOfMeasure::create(['code' => 'kg', 'name' => 'Kilogramo', 'symbol' => 'kg']);

    $this->product = Product::create([
        'name' => 'Pintura Auditada',
        'category_id' => ProductCategory::create(['name' => 'Pinturas'])->id,
        'unit_of_measure_id' => $this->unit->id,
        'cif_percentage' => 0,
        'price_threshold' => 0,
        'is_active' => true,
    ]);

    $category = RawMaterialCategory::create(['name' => 'Materia prima auditada', 'is_active' => true]);

    $this->materials = collect(['RM-AUD-01', 'RM-AUD-02', 'RM-AUD-03', 'RM-AUD-04'])->map(fn (string $code) => RawMaterial::create([
        'code' => $code,
        'category_id' => $category->id,
        'unit_of_measure_id' => $this->unit->id,
        'current_price' => 10,
        'minimum_stock' => 0,
        'alert_days_before_expiry' => 30,
        'is_active' => true,
    ]));
});

/**
 * @param  array<int, array{0: RawMaterial, 1: string}>  $lines
 * @return array<int, array<string, mixed>>
 */
function auditedFormulaLines(array $lines, UnitOfMeasure $unit): array
{
    return array_map(fn (array $line) => [
        'raw_material_id' => $line[0]->id,
        'quantity' => $line[1],
        'unit_of_measure_id' => $unit->id,
    ], $lines);
}

/**
 * Crea la fórmula por la ruta real y borra la actividad que dejó, para que cada prueba vea solo lo que hace después.
 *
 * @param  array<int, array{0: RawMaterial, 1: string}>  $lines
 */
function auditedFormula(Product $product, UnitOfMeasure $unit, array $lines): Formula
{
    test()->post(route('formulas.store'), [
        'product_id' => $product->id,
        'details' => auditedFormulaLines($lines, $unit),
    ])->assertSessionHasNoErrors();

    $formula = Formula::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    Activity::query()->delete();

    return $formula;
}

/**
 * @return Collection<int, Activity>
 */
function formulaLineActivities(?string $event = null): Collection
{
    return Activity::query()
        ->where('log_name', 'formulas')
        ->where('subject_type', FormulaDetail::class)
        ->when($event !== null, fn ($query) => $query->where('event', $event))
        ->orderBy('id')
        ->get();
}

test('crear una fórmula deja una actividad por línea con producto, versión, código y valores', function () {
    [$first, $second, $third] = $this->materials;

    $this->post(route('formulas.store'), [
        'product_id' => $this->product->id,
        'details' => auditedFormulaLines([[$first, '2'], [$second, '3.5'], [$third, '0.25']], $this->unit),
    ])->assertSessionHasNoErrors();

    $formula = Formula::query()->where('product_id', $this->product->id)->sole();
    $created = formulaLineActivities('created');

    expect($created)->toHaveCount(3)
        ->and($created->pluck('description')->all())->toBe([
            'Línea de fórmula "PINTURA AUDITADA v1 · paso 1 · RM-AUD-01" creada',
            'Línea de fórmula "PINTURA AUDITADA v1 · paso 2 · RM-AUD-02" creada',
            'Línea de fórmula "PINTURA AUDITADA v1 · paso 3 · RM-AUD-03" creada',
        ])
        ->and($created->every(fn (Activity $activity) => (int) $activity->causer_id === $this->admin->id))->toBeTrue();

    expect($created->map(fn (Activity $activity) => $activity->properties['attributes'])->all())->toBe([
        ['formula_id' => $formula->id, 'raw_material_id' => $first->id, 'product_id' => null, 'costing_basis' => null, 'costing_variant_id' => null, 'quantity' => '2.0000', 'unit_of_measure_id' => $this->unit->id],
        ['formula_id' => $formula->id, 'raw_material_id' => $second->id, 'product_id' => null, 'costing_basis' => null, 'costing_variant_id' => null, 'quantity' => '3.5000', 'unit_of_measure_id' => $this->unit->id],
        ['formula_id' => $formula->id, 'raw_material_id' => $third->id, 'product_id' => null, 'costing_basis' => null, 'costing_variant_id' => null, 'quantity' => '0.2500', 'unit_of_measure_id' => $this->unit->id],
    ]);
});

test('guardar la fórmula sin cambiar sus líneas no deja actividad de líneas', function () {
    [$first, $second, $third] = $this->materials;
    $lines = [[$first, '2'], [$second, '3.5'], [$third, '0.25']];
    $formula = auditedFormula($this->product, $this->unit, $lines);
    $lineIds = $formula->details()->pluck('id')->all();

    $this->put(route('formulas.update', $formula), [
        'notes' => 'Solo cambian las notas',
        'details' => auditedFormulaLines($lines, $this->unit),
    ])->assertSessionHasNoErrors();

    expect(formulaLineActivities())->toBeEmpty()
        ->and($formula->details()->pluck('id')->all())->toBe($lineIds);
});

test('cambiar una cantidad deja un solo registro con la cantidad vieja y la nueva', function () {
    [$first, $second, $third] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5'], [$third, '0.25']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$second, '4'], [$third, '0.25']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('updated')
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 2 · RM-AUD-02" actualizada')
        ->and($activities->first()->properties['attributes'])->toBe(['quantity' => '4.0000'])
        ->and($activities->first()->properties['old'])->toBe(['quantity' => '3.5000']);
});

test('quitar un paso del medio deja un solo borrado, sin cambios en los pasos que suben', function () {
    [$first, $second, $third] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5'], [$third, '0.25']]);
    $thirdLineId = $formula->details()->where('step_order', 3)->value('id');

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$third, '0.25']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('deleted')
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 2 · RM-AUD-02" eliminada')
        ->and($activities->first()->properties['old'])->toBe([
            'formula_id' => $formula->id, 'raw_material_id' => $second->id, 'product_id' => null, 'costing_basis' => null, 'costing_variant_id' => null, 'quantity' => '3.5000', 'unit_of_measure_id' => $this->unit->id,
        ])
        // La tercera línea es la misma (se corrió al paso 2), no una nueva.
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->id === $thirdLineId, $line->step_order, $line->raw_material_id])->all())
        ->toBe([[false, 1, $first->id], [true, 2, $third->id]]);
});

test('agregar un paso en el medio deja una sola creación', function () {
    [$first, $second, , $fourth] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$fourth, '1'], [$second, '3.5']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('created')
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 2 · RM-AUD-04" creada')
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->step_order, $line->raw_material_id])->all())
        ->toBe([[1, $first->id], [2, $fourth->id], [3, $second->id]]);
});

test('cambiar la materia prima de un paso deja el borrado de la vieja y la creación de la nueva', function () {
    [$first, $second, $third, $fourth] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5'], [$third, '0.25']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$fourth, '3.5'], [$third, '0.25']], $this->unit),
    ])->assertSessionHasNoErrors();

    expect(formulaLineActivities()->map(fn (Activity $activity) => [$activity->event, $activity->description])->all())->toBe([
        ['deleted', 'Línea de fórmula "PINTURA AUDITADA v1 · paso 2 · RM-AUD-02" eliminada'],
        ['created', 'Línea de fórmula "PINTURA AUDITADA v1 · paso 2 · RM-AUD-04" creada'],
    ]);
});

test('agregar un paso al final solo lo crea', function () {
    [$first, $second, , $fourth] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$second, '3.5'], [$fourth, '1']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('created')
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 3 · RM-AUD-04" creada');
});

test('la misma materia prima en dos pasos se actualiza por paso (D-FOR2)', function () {
    [$first, $second] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '1'], [$first, '3']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$second, '1'], [$first, '5']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 3 · RM-AUD-01" actualizada')
        ->and($activities->first()->properties['attributes'])->toBe(['quantity' => '5.0000'])
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->step_order, $line->raw_material_id, $line->quantity])->all())
        ->toBe([[1, $first->id, '2.0000'], [2, $second->id, '1.0000'], [3, $first->id, '5.0000']]);
});

test('con la misma materia prima en dos pasos, quitar la primera borra esa y no la otra (D-FOR2)', function () {
    [$first, $second] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '1'], [$first, '3']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$second, '1'], [$first, '3']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 1 · RM-AUD-01" eliminada')
        ->and($activities->first()->properties['old']['quantity'])->toBe('2.0000')
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->step_order, $line->raw_material_id, $line->quantity])->all())
        ->toBe([[1, $second->id, '1.0000'], [2, $first->id, '3.0000']]);
});

test('con la misma materia prima en dos pasos y distinta cantidad, quitar una borra esa y no cambia la otra (D-FOR2)', function () {
    [$first] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$first, '3']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '3']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('deleted')
        ->and($activities->first()->description)->toBe('Línea de fórmula "PINTURA AUDITADA v1 · paso 1 · RM-AUD-01" eliminada')
        ->and($activities->first()->properties['old']['quantity'])->toBe('2.0000')
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->step_order, $line->quantity])->all())
        ->toBe([[1, '3.0000']]);
});

test('eliminar una fórmula sin uso deja el borrado de cada línea con sus valores', function () {
    [$first, $second] = $this->materials;
    // La primera fórmula del producto es la de referencia de precio y no se elimina: se borra la segunda.
    auditedFormula($this->product, $this->unit, [[$first, '1']]);
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5']]);

    expect(app(DeleteFormulaAction::class)->execute($formula))->toBeTrue();

    $deleted = formulaLineActivities('deleted');

    expect(Formula::query()->whereKey($formula->id)->exists())->toBeFalse()
        ->and($deleted->pluck('description')->all())->toBe([
            'Línea de fórmula "PINTURA AUDITADA v2 · paso 1 · RM-AUD-01" eliminada',
            'Línea de fórmula "PINTURA AUDITADA v2 · paso 2 · RM-AUD-02" eliminada',
        ])
        ->and($deleted->map(fn (Activity $activity) => $activity->properties['old']['quantity'])->all())->toBe(['2.0000', '3.5000']);
});

test('una fórmula con historial no se elimina y sus líneas siguen sin registros de borrado', function () {
    [$first, $second] = $this->materials;
    auditedFormula($this->product, $this->unit, [[$first, '1']]);
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5']]);

    ProductionCost::create([
        'product_id' => $this->product->id,
        'formula_id' => $formula->id,
        'cost' => '100',
        'calculated_at' => now(),
    ]);

    expect(app(DeleteFormulaAction::class)->execute($formula))->toBeFalse()
        ->and($formula->details()->count())->toBe(2)
        ->and(formulaLineActivities('deleted'))->toBeEmpty();
});

test('la descripción de la auditoría no lee la fórmula, el producto ni la materia prima por línea', function () {
    // Un solo listener: cuenta solo mientras `$state->selects` no es null.
    $state = (object) ['selects' => null];

    DB::listen(function (QueryExecuted $query) use ($state): void {
        // Solo las lecturas que sirven a la descripción (fórmula, producto, materia prima). Spatie vuelve a leer cada
        // línea actualizada para registrar sus valores: eso es suyo, en todo modelo auditado, y no depende de esto.
        $sql = strtolower(ltrim($query->sql));

        if ($state->selects !== null && str_starts_with($sql, 'select') && preg_match('/from "(formulas|products|raw_materials)"/', $sql) === 1) {
            $state->selects++;
        }
    });

    $selectsWhileEditing = function (int $lineCount) use ($state): int {
        $product = Product::create([
            'name' => "Producto de {$lineCount} líneas",
            'category_id' => $this->product->category_id,
            'unit_of_measure_id' => $this->unit->id,
            'cif_percentage' => 0,
            'price_threshold' => 0,
            'is_active' => true,
        ]);

        $lines = collect(range(0, $lineCount - 1))
            ->map(fn (int $index) => [$this->materials[$index % 3], '1'])
            ->all();
        $formula = auditedFormula($product, $this->unit, $lines);

        // Cambia todas las cantidades, quita la primera línea y agrega una al final: actualiza, borra y crea.
        $changed = [...array_map(fn (array $line) => [$line[0], '2'], array_slice($lines, 1)), [$this->materials[3], '7']];

        $state->selects = 0;
        DB::transaction(fn () => Formula::query()->findOrFail($formula->id)->replaceDetails(auditedFormulaLines($changed, $this->unit)));
        $selects = $state->selects;
        $state->selects = null;

        return $selects;
    };

    $withThree = $selectsWhileEditing(3);
    $withTen = $selectsWhileEditing(10);

    // Crear la segunda fórmula borró la actividad de la primera: queda la edición de la de 10 líneas.
    expect(formulaLineActivities('updated'))->toHaveCount(9)
        ->and(formulaLineActivities('deleted'))->toHaveCount(1)
        ->and(formulaLineActivities('created'))->toHaveCount(1)
        ->and($withTen)->toBe($withThree);
});
