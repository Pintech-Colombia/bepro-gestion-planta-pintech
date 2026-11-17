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

    $category = RawMaterialCategory::create(['code' => 'RM-AUD', 'name' => 'Materia prima auditada', 'is_active' => true]);

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
            'Línea 1 de "PINTURA AUDITADA" v1 (RM-AUD-01) creada',
            'Línea 2 de "PINTURA AUDITADA" v1 (RM-AUD-02) creada',
            'Línea 3 de "PINTURA AUDITADA" v1 (RM-AUD-03) creada',
        ])
        ->and($created->every(fn (Activity $activity) => (int) $activity->causer_id === $this->admin->id))->toBeTrue();

    expect($created->map(fn (Activity $activity) => $activity->properties['attributes'])->all())->toBe([
        ['formula_id' => $formula->id, 'raw_material_id' => $first->id, 'quantity' => '2.0000', 'unit_of_measure_id' => $this->unit->id, 'step_order' => 1],
        ['formula_id' => $formula->id, 'raw_material_id' => $second->id, 'quantity' => '3.5000', 'unit_of_measure_id' => $this->unit->id, 'step_order' => 2],
        ['formula_id' => $formula->id, 'raw_material_id' => $third->id, 'quantity' => '0.2500', 'unit_of_measure_id' => $this->unit->id, 'step_order' => 3],
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
        ->and($activities->first()->description)->toBe('Línea 2 de "PINTURA AUDITADA" v1 (RM-AUD-02) actualizada')
        ->and($activities->first()->properties['attributes'])->toBe(['quantity' => '4.0000'])
        ->and($activities->first()->properties['old'])->toBe(['quantity' => '3.5000']);
});

test('quitar un paso del medio actualiza el que sube y borra el último con sus valores', function () {
    [$first, $second, $third] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '3.5'], [$third, '0.25']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$third, '0.25']], $this->unit),
    ])->assertSessionHasNoErrors();

    $updated = formulaLineActivities('updated');
    $deleted = formulaLineActivities('deleted');

    expect(formulaLineActivities('created'))->toBeEmpty()
        ->and($updated)->toHaveCount(1)
        ->and($updated->first()->description)->toBe('Línea 2 de "PINTURA AUDITADA" v1 (RM-AUD-03) actualizada')
        ->and($updated->first()->properties['old'])->toBe(['raw_material_id' => $second->id, 'quantity' => '3.5000'])
        ->and($updated->first()->properties['attributes'])->toBe(['raw_material_id' => $third->id, 'quantity' => '0.2500'])
        ->and($deleted)->toHaveCount(1)
        ->and($deleted->first()->description)->toBe('Línea 3 de "PINTURA AUDITADA" v1 (RM-AUD-03) eliminada')
        ->and($deleted->first()->properties['old'])->toBe([
            'formula_id' => $formula->id, 'raw_material_id' => $third->id, 'quantity' => '0.2500', 'unit_of_measure_id' => $this->unit->id, 'step_order' => 3,
        ])
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->step_order, $line->raw_material_id])->all())
        ->toBe([[1, $first->id], [2, $third->id]]);
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
        ->and($activities->first()->description)->toBe('Línea 3 de "PINTURA AUDITADA" v1 (RM-AUD-04) creada');
});

test('la misma materia prima en dos pasos se actualiza por paso (D-FOR2)', function () {
    [$first, $second] = $this->materials;
    $formula = auditedFormula($this->product, $this->unit, [[$first, '2'], [$second, '1'], [$first, '3']]);

    $this->put(route('formulas.update', $formula), [
        'details' => auditedFormulaLines([[$first, '2'], [$second, '1'], [$first, '5']], $this->unit),
    ])->assertSessionHasNoErrors();

    $activities = formulaLineActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->description)->toBe('Línea 3 de "PINTURA AUDITADA" v1 (RM-AUD-01) actualizada')
        ->and($activities->first()->properties['attributes'])->toBe(['quantity' => '5.0000'])
        ->and($formula->details()->get()->map(fn (FormulaDetail $line) => [$line->step_order, $line->raw_material_id, $line->quantity])->all())
        ->toBe([[1, $first->id, '2.0000'], [2, $second->id, '1.0000'], [3, $first->id, '5.0000']]);
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
            'Línea 1 de "PINTURA AUDITADA" v2 (RM-AUD-01) eliminada',
            'Línea 2 de "PINTURA AUDITADA" v2 (RM-AUD-02) eliminada',
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
    $selectsWhileEditing = function (int $lineCount): int {
        $product = Product::create([
            'name' => "Producto de {$lineCount} líneas",
            'category_id' => $this->product->category_id,
            'unit_of_measure_id' => $this->unit->id,
            'cif_percentage' => 0,
            'price_threshold' => 0,
            'is_active' => true,
        ]);

        $lines = collect(range(0, $lineCount - 1))
            ->map(fn (int $index) => [$this->materials[$index % 4], '1'])
            ->all();
        $formula = auditedFormula($product, $this->unit, $lines);

        $changed = array_map(fn (array $line) => [$line[0], '2'], $lines);
        $selects = 0;

        // Solo las lecturas que sirven a la descripción (fórmula, producto, materia prima). Spatie vuelve a leer cada
        // línea actualizada para registrar sus valores: eso es suyo, en todo modelo auditado, y no depende de esto.
        DB::listen(function (QueryExecuted $query) use (&$selects): void {
            $sql = strtolower(ltrim($query->sql));

            if (str_starts_with($sql, 'select') && preg_match('/from "(formulas|products|raw_materials)"/', $sql) === 1) {
                $selects++;
            }
        });

        DB::transaction(fn () => Formula::query()->findOrFail($formula->id)->replaceDetails(auditedFormulaLines($changed, $this->unit)));

        return $selects;
    };

    $withThree = $selectsWhileEditing(3);
    $withTen = $selectsWhileEditing(10);

    // Crear la segunda fórmula borró la actividad de la primera: quedan las 10 líneas actualizadas de la última.
    expect(formulaLineActivities('updated'))->toHaveCount(10)
        ->and($withTen)->toBe($withThree);
});
