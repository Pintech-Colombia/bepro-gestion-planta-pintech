<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use Spatie\Activitylog\Models\Activity;

// Sustancia controlada (SICOQ): por ahora solo la marca en la materia prima, auditada.

/** @return array<string, mixed> */
function controlledMaterialPayload(array $overrides = []): array
{
    return [
        'code' => 'S - 12',
        'category_id' => RawMaterialCategory::factory()->create()->id,
        'unit_of_measure_id' => UnitOfMeasure::factory()->create()->id,
        'minimum_stock' => '0',
        'alert_days_before_expiry' => 90,
        'is_active' => true,
        ...$overrides,
    ];
}

it('registra una materia prima como sustancia controlada', function () {
    actingAsRole(SystemRole::SuperAdmin);

    $this->post(route('raw-materials.store'), controlledMaterialPayload(['is_controlled_substance' => true]))
        ->assertSessionHasNoErrors();

    expect(RawMaterial::query()->where('code', 'S - 12')->sole()->is_controlled_substance)->toBeTrue();
});

it('sin marcarla, la materia prima no es controlada', function () {
    actingAsRole(SystemRole::SuperAdmin);

    $this->post(route('raw-materials.store'), controlledMaterialPayload())->assertSessionHasNoErrors();

    expect(RawMaterial::query()->where('code', 'S - 12')->sole()->is_controlled_substance)->toBeFalse();
});

it('cambia la marca al editar y queda en la auditoría', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $material = RawMaterial::factory()->create(['code' => 'S - 13']);

    $this->put(route('raw-materials.update', $material), controlledMaterialPayload([
        'code' => 'S - 13',
        'category_id' => $material->category_id,
        'unit_of_measure_id' => $material->unit_of_measure_id,
        'is_controlled_substance' => true,
    ]))->assertSessionHasNoErrors();

    $activity = Activity::query()->where('subject_type', RawMaterial::class)->where('subject_id', $material->id)->latest('id')->first();

    expect($material->fresh()->is_controlled_substance)->toBeTrue()
        ->and($activity->properties['attributes']['is_controlled_substance'] ?? null)->toBeTrue();
});

it('editar sin enviar la marca la conserva', function () {
    actingAsRole(SystemRole::SuperAdmin);
    $material = RawMaterial::factory()->create(['code' => 'S - 8', 'is_controlled_substance' => true]);

    $this->put(route('raw-materials.update', $material), controlledMaterialPayload([
        'code' => 'S - 8',
        'category_id' => $material->category_id,
        'unit_of_measure_id' => $material->unit_of_measure_id,
        'minimum_stock' => '5',
    ]))->assertSessionHasNoErrors();

    expect($material->fresh()->is_controlled_substance)->toBeTrue();
});
