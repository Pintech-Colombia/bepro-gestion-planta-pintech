<?php

declare(strict_types=1);

use App\Enums\RawMaterialType;
use App\Enums\SystemRole;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

// Catálogo de categorías de materia prima (3.2), en Configuración → Catálogos. El tipo de insumo decide dónde se
// ofrecen sus materias primas (decisión del 2026-09-29).

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rawCategoryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Resinas',
        'description' => null,
        'family_id' => $overrides['family_id'] ?? RawMaterialFamily::factory()->create()->id,
        'type' => RawMaterialType::Chemical->value,
        'is_active' => true,
    ], $overrides);
}

it('lista las categorías con su tipo y cuántas materias primas tienen', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $containers = RawMaterialCategory::factory()->container()->create(['name' => 'Envases metálicos']);
    RawMaterial::factory()->count(2)->create(['category_id' => $containers->id]);

    $this->get(route('catalogs.raw-material-categories.index'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Catalogs/RawMaterialCategories/Index')
            ->where('can.create', true)
            ->has('typeOptions', 4)
            ->where('categories.data', fn ($rows) => ($row = collect($rows)->firstWhere('id', $containers->id))
                && $row['type'] === 'container'
                && $row['type_label'] === 'Envase'
                && $row['raw_materials_count'] === 2));
});

it('filtra por tipo de insumo', function () {
    actingAsRole(SystemRole::SuperAdmin);
    RawMaterialCategory::factory()->create(['name' => 'RESINAS']);
    RawMaterialCategory::factory()->label()->create(['name' => 'ETIQUETAS']);

    $this->get(route('catalogs.raw-material-categories.index', ['type' => 'label']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('categories.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['ETIQUETAS']));
});

it('deja a Admin consultar las categorías pero no modificarlas', function () {
    actingAsRole(SystemRole::Admin);
    $category = RawMaterialCategory::factory()->create();

    $this->get(route('catalogs.raw-material-categories.index'))->assertSuccessful();
    $this->post(route('catalogs.raw-material-categories.store'), rawCategoryPayload())->assertForbidden();
    $this->put(route('catalogs.raw-material-categories.update', $category), rawCategoryPayload())->assertForbidden();
    $this->delete(route('catalogs.raw-material-categories.destroy', $category))->assertForbidden();
});

it('niega las categorías a los roles sin catalogs.view', function (SystemRole $role) {
    actingAsRole($role);

    $this->get(route('catalogs.raw-material-categories.index'))->assertForbidden();
    $this->get(route('catalogs.product-categories.index'))->assertForbidden();
})->with([SystemRole::Production, SystemRole::Operator, SystemRole::Commercial]);

it('crea una categoría sin código, identificada por familia y nombre, y la audita', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $packaging = RawMaterialFamily::factory()->create(['name' => 'EMPAQUE']);

    // Un `code` que llegue (un formulario viejo en caché) se ignora: si llegara a la base, el guardado fallaría, porque
    // la columna ya no existe.
    $this->post(route('catalogs.raw-material-categories.store'), rawCategoryPayload([
        'code' => 'BANDEJAS', 'name' => '  Bandejas ', 'family_id' => $packaging->id, 'type' => 'secondary_packaging',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('catalogs.raw-material-categories.index'));

    $category = RawMaterialCategory::query()->where('name', 'Bandejas')->sole();

    expect($category->type)->toBe(RawMaterialType::SecondaryPackaging)
        ->and(Activity::query()->where('subject_type', RawMaterialCategory::class)->where('subject_id', $category->id)->value('description'))
        ->toBe('Categoría de materia prima "EMPAQUE · Bandejas" creada');
});

it('valida los campos de la categoría', function (array $overrides, string $field) {
    actingAsRole(SystemRole::SuperAdmin);
    $existing = RawMaterialCategory::factory()->create(['name' => 'Resinas']);

    $this->post(route('catalogs.raw-material-categories.store'), rawCategoryPayload([...$overrides, 'family_id' => $existing->family_id]))
        ->assertSessionHasErrors($field);
})->with([
    // En la misma familia, dos categorías con el mismo nombre no se distinguirían en el selector de la materia prima.
    'nombre repetido en la familia, aunque cambien las mayúsculas' => [['name' => 'RESINAS'], 'name'],
    'sin nombre' => [['name' => ''], 'name'],
    'tipo que no existe' => [['name' => 'Otra', 'type' => 'pintura'], 'type'],
]);

it('no deja cambiar el tipo de una categoría que tiene materias primas', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $category = RawMaterialCategory::factory()->container()->create([]);
    RawMaterial::factory()->create(['category_id' => $category->id]);

    $this->put(route('catalogs.raw-material-categories.update', $category), rawCategoryPayload(['type' => 'chemical']))
        ->assertSessionHasErrors(['type' => 'No se puede cambiar el tipo: la categoría tiene 1 materia prima. Muévela a otra categoría primero.']);

    expect($category->fresh()->type)->toBe(RawMaterialType::Container);
});

it('deja cambiar el tipo de una categoría vacía y editar el resto de una con materias primas', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $empty = RawMaterialCategory::factory()->create([]);
    $used = RawMaterialCategory::factory()->container()->create([]);
    RawMaterial::factory()->create(['category_id' => $used->id]);

    $this->put(route('catalogs.raw-material-categories.update', $empty), rawCategoryPayload(['type' => 'label']))
        ->assertSessionHasNoErrors();
    $this->put(route('catalogs.raw-material-categories.update', $used), rawCategoryPayload(['name' => 'Envases de lata', 'type' => 'container']))
        ->assertSessionHasNoErrors();

    expect($empty->fresh()->type)->toBe(RawMaterialType::Label)
        ->and($used->fresh()->name)->toBe('Envases de lata');
});

it('no deja poner el nombre de otra categoría de la familia al editar, pero sí cambiar las mayúsculas del propio', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $family = RawMaterialFamily::factory()->create();
    RawMaterialCategory::factory()->create(['name' => 'Resinas', 'family_id' => $family->id]);
    $solvents = RawMaterialCategory::factory()->create(['name' => 'Solventes', 'family_id' => $family->id]);

    $this->put(route('catalogs.raw-material-categories.update', $solvents), rawCategoryPayload(['name' => 'resinas', 'family_id' => $family->id]))
        ->assertSessionHasErrors('name');
    $this->put(route('catalogs.raw-material-categories.update', $solvents), rawCategoryPayload(['name' => 'SOLVENTES', 'family_id' => $family->id]))
        ->assertSessionHasNoErrors();

    expect($solvents->fresh()->name)->toBe('SOLVENTES');
});

it('conserva el estado de la categoría si la edición no lo envía', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $category = RawMaterialCategory::factory()->create(['is_active' => false]);
    $payload = rawCategoryPayload();
    unset($payload['is_active']);

    $this->put(route('catalogs.raw-material-categories.update', $category), $payload)->assertSessionHasNoErrors();

    expect($category->fresh()->is_active)->toBeFalse();
});

it('elimina una categoría sin materias primas y rechaza una con materias primas', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $empty = RawMaterialCategory::factory()->create();
    $used = RawMaterialCategory::factory()->create();
    RawMaterial::factory()->create(['category_id' => $used->id]);

    $this->delete(route('catalogs.raw-material-categories.destroy', $empty))
        ->assertRedirect(route('catalogs.raw-material-categories.index'));
    $this->delete(route('catalogs.raw-material-categories.destroy', $used))
        ->assertInertiaFlash('error', 'La categoría tiene materias primas. Desactívala en su lugar.');

    expect(RawMaterialCategory::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(RawMaterialCategory::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('exige una familia activa al crear la categoría', function (?Closure $family) {
    actingAsRole(SystemRole::SuperAdmin);

    $this->post(route('catalogs.raw-material-categories.store'), rawCategoryPayload(['family_id' => $family ? $family()->id : '']))
        ->assertSessionHasErrors('family_id');
})->with([
    'sin familia' => [null],
    'familia inactiva' => [fn () => RawMaterialFamily::factory()->create(['is_active' => false])],
]);

it('conserva al editar la familia que se desactivó, pero no deja pasarse a otra inactiva', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $family = RawMaterialFamily::factory()->create();
    $category = RawMaterialCategory::factory()->create(['family_id' => $family->id]);
    $family->update(['is_active' => false]);
    $otherInactive = RawMaterialFamily::factory()->create(['is_active' => false]);

    $this->get(route('catalogs.raw-material-categories.edit', $category))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('category.family_id', $family->id)
            ->where('familyOptions', fn ($options) => collect($options)->pluck('value')->all() === [(string) $family->id]));

    $this->put(route('catalogs.raw-material-categories.update', $category), rawCategoryPayload(['family_id' => $family->id]))
        ->assertSessionHasNoErrors();
    $this->put(route('catalogs.raw-material-categories.update', $category), rawCategoryPayload(['family_id' => $otherInactive->id]))
        ->assertSessionHasErrors('family_id');
});

it('filtra las categorías por familia y muestra el nombre de la familia', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $additives = RawMaterialFamily::factory()->create(['name' => 'ADITIVO']);
    RawMaterialCategory::factory()->create(['name' => 'DISPERSANTE', 'family_id' => $additives->id]);
    RawMaterialCategory::factory()->create(['name' => 'RESINAS']);

    $this->get(route('catalogs.raw-material-categories.index', ['family' => $additives->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('categories.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['DISPERSANTE']
                && $rows[0]['family_name'] === 'ADITIVO'));
});

it('deja el mismo nombre de categoría en familias distintas', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $resins = RawMaterialFamily::factory()->create(['name' => 'RESINA']);
    $solvents = RawMaterialFamily::factory()->create(['name' => 'SOLVENTE']);
    RawMaterialCategory::factory()->create(['name' => 'BASE AGUA', 'family_id' => $resins->id]);

    $this->post(route('catalogs.raw-material-categories.store'), rawCategoryPayload(['name' => 'BASE AGUA', 'family_id' => $solvents->id]))
        ->assertSessionHasNoErrors();

    expect(RawMaterialCategory::query()->where('name', 'BASE AGUA')->count())->toBe(2);
});
