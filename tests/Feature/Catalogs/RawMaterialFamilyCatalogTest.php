<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

// Familias de materia prima (Aditivo, Pigmento, Resina…), en Configuración → Catálogos: agrupan las categorías.

it('lista las familias con cuántas categorías tienen', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $additives = RawMaterialFamily::factory()->create(['name' => 'ADITIVO']);
    RawMaterialCategory::factory()->count(2)->create(['family_id' => $additives->id]);

    $this->get(route('catalogs.raw-material-families.index'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Catalogs/RawMaterialFamilies/Index')
            ->where('can.create', true)
            ->where('families.data', fn ($rows) => ($row = collect($rows)->firstWhere('id', $additives->id))
                && $row['categories_count'] === 2));
});

it('deja a Admin consultar las familias pero no modificarlas', function () {
    actingAsRole(SystemRole::Admin);
    $family = RawMaterialFamily::factory()->create();

    $this->get(route('catalogs.raw-material-families.index'))->assertSuccessful();
    $this->post(route('catalogs.raw-material-families.store'), ['name' => 'PIGMENTO'])->assertForbidden();
    $this->put(route('catalogs.raw-material-families.update', $family), ['name' => 'PIGMENTO'])->assertForbidden();
    $this->delete(route('catalogs.raw-material-families.destroy', $family))->assertForbidden();
});

it('niega las familias a los roles sin catalogs.view', function (SystemRole $role) {
    actingAsRole($role);

    $this->get(route('catalogs.raw-material-families.index'))->assertForbidden();
})->with([SystemRole::Production, SystemRole::Operator, SystemRole::Commercial]);

it('crea una familia y la audita', function () {
    actingAsRole(SystemRole::SuperAdmin);

    $this->post(route('catalogs.raw-material-families.store'), ['name' => '  ADITIVO ', 'description' => ''])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('catalogs.raw-material-families.index'));

    $family = RawMaterialFamily::query()->where('name', 'ADITIVO')->sole();

    expect($family->description)->toBeNull()
        ->and($family->is_active)->toBeTrue()
        ->and(Activity::query()->where('subject_type', RawMaterialFamily::class)->where('subject_id', $family->id)->value('description'))
        ->toBe('Familia de materia prima "ADITIVO" creada');
});

it('no deja repetir el nombre de una familia, aunque cambien las mayúsculas', function () {
    actingAsRole(SystemRole::SuperAdmin);
    RawMaterialFamily::factory()->create(['name' => 'ADITIVO']);
    $pigments = RawMaterialFamily::factory()->create(['name' => 'PIGMENTO']);

    $this->post(route('catalogs.raw-material-families.store'), ['name' => 'aditivo'])->assertSessionHasErrors('name');
    $this->put(route('catalogs.raw-material-families.update', $pigments), ['name' => 'Aditivo'])->assertSessionHasErrors('name');
    $this->put(route('catalogs.raw-material-families.update', $pigments), ['name' => 'Pigmento'])->assertSessionHasNoErrors();

    expect($pigments->fresh()->name)->toBe('Pigmento');
});

it('conserva el estado de la familia si la edición no lo envía', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $family = RawMaterialFamily::factory()->create(['name' => 'ADITIVO', 'is_active' => false]);

    $this->put(route('catalogs.raw-material-families.update', $family), ['name' => 'ADITIVO'])->assertSessionHasNoErrors();

    expect($family->fresh()->is_active)->toBeFalse();
});

it('elimina una familia sin categorías y rechaza una con categorías', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $empty = RawMaterialFamily::factory()->create();
    $used = RawMaterialFamily::factory()->create();
    RawMaterialCategory::factory()->create(['family_id' => $used->id]);

    $this->delete(route('catalogs.raw-material-families.destroy', $empty))
        ->assertRedirect(route('catalogs.raw-material-families.index'));
    $this->delete(route('catalogs.raw-material-families.destroy', $used))
        ->assertInertiaFlash('error', 'La familia tiene categorías. Desactívala en su lugar.');

    expect(RawMaterialFamily::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(RawMaterialFamily::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('guarda la tolerancia de consumo de la familia, vacía como el general, y audita el cambio', function () {
    actingAsRole(SystemRole::SuperAdmin);

    $this->post(route('catalogs.raw-material-families.store'), ['name' => 'RESINA', 'consumption_tolerance_percentage' => '10'])
        ->assertSessionHasNoErrors();
    $this->post(route('catalogs.raw-material-families.store'), ['name' => 'PIGMENTO', 'consumption_tolerance_percentage' => ''])
        ->assertSessionHasNoErrors();

    $resina = RawMaterialFamily::query()->where('name', 'RESINA')->sole();
    $this->put(route('catalogs.raw-material-families.update', $resina), ['name' => 'RESINA', 'consumption_tolerance_percentage' => '12.5'])
        ->assertSessionHasNoErrors();

    $log = Activity::query()->where('subject_type', RawMaterialFamily::class)->where('subject_id', $resina->id)->where('event', 'updated')->sole();

    expect($resina->fresh()->consumption_tolerance_percentage)->toBe('12.50')
        ->and(RawMaterialFamily::query()->where('name', 'PIGMENTO')->sole()->consumption_tolerance_percentage)->toBeNull()
        ->and($log->properties['old'])->toBe(['consumption_tolerance_percentage' => '10.00'])
        ->and($log->properties['attributes'])->toBe(['consumption_tolerance_percentage' => '12.50']);
});

it('rechaza una tolerancia negativa, mayor que 100 o con más de dos decimales', function (string $value) {
    actingAsRole(SystemRole::SuperAdmin);

    $this->post(route('catalogs.raw-material-families.store'), ['name' => 'RESINA', 'consumption_tolerance_percentage' => $value])
        ->assertSessionHasErrors('consumption_tolerance_percentage');
})->with(['-1', '100.01', '10.125']);

it('el listado y el formulario muestran el % general que usa la familia sin el suyo', function () {
    actingAsRole(SystemRole::SuperAdmin);
    config(['production.consumption_tolerance_percentage' => '5.00']);
    $family = RawMaterialFamily::factory()->create(['consumption_tolerance_percentage' => '10.00']);

    $this->get(route('catalogs.raw-material-families.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generalTolerance', '5.00')
            ->where('families.data', fn ($rows) => collect($rows)->firstWhere('id', $family->id)['consumption_tolerance_percentage'] === '10.00'));

    $this->get(route('catalogs.raw-material-families.edit', $family))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('generalTolerance', '5.00'));
});
