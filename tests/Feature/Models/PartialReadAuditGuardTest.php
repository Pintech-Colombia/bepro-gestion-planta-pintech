<?php

declare(strict_types=1);

use App\Models\Alert;
use App\Models\InventoryBatch;
use App\Models\RawMaterial;
use App\Models\User;

// La guardia de tests/Support/PartialReadAuditGuard.php, activa en todas las pruebas: un modelo auditado leído con
// columnas parciales no se guarda, porque la auditoría registraría como cambio lo que no se leyó.

beforeEach(function () {
    $this->rawMaterial = RawMaterial::factory()->create(['current_price' => '100']);
});

it('no deja guardar un modelo auditado leído con columnas parciales', function () {
    $partial = RawMaterial::query()->select(['id', 'current_price'])->findOrFail($this->rawMaterial->id);

    expect(fn () => $partial->update(['current_price' => '200']))
        ->toThrow(LogicException::class, 'Se guarda '.RawMaterial::class." #{$this->rawMaterial->id} leído sin la columna auditada «code»");

    expect((string) $this->rawMaterial->fresh()->current_price)->toBe('100.0000');
});

it('tampoco uno cargado como relación con columnas parciales', function () {
    $batch = InventoryBatch::factory()->create(['raw_material_id' => $this->rawMaterial->id]);
    $batch = InventoryBatch::query()->with('rawMaterial:id,code')->findOrFail($batch->id);

    expect(fn () => $batch->rawMaterial->update(['current_price' => '200']))->toThrow(LogicException::class);
});

it('deja guardar un modelo auditado leído completo', function () {
    $full = RawMaterial::query()->findOrFail($this->rawMaterial->id);

    $full->update(['current_price' => '200']);

    expect((string) $this->rawMaterial->fresh()->current_price)->toBe('200.0000');
});

it('no confunde un modelo recién creado con una lectura parcial, aunque actingAs apague wasRecentlyCreated', function () {
    // Creado sin firma: su original no trae `signature_path`, pero no vino de la base.
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->update(['job_title' => 'Jefe de planta']);

    expect($user->fresh()->job_title)->toBe('Jefe de planta');
});

it('no revisa modelos sin auditoría', function () {
    $alert = Alert::factory()->create(['raw_material_id' => $this->rawMaterial->id]);
    $partial = Alert::query()->select(['id', 'is_resolved'])->findOrFail($alert->id);

    $partial->update(['is_resolved' => true]);

    expect($alert->fresh()->is_resolved)->toBeTrue();
});
