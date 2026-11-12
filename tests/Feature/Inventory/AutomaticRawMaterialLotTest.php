<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\InventoryBatch;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\RawMaterialLotNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->admin = actingAsRole(SystemRole::Admin, ['email_verified_at' => now()]);
    $this->warehouse = Warehouse::factory()->factory()->create();
    $this->rawMaterial = RawMaterial::factory()->create();
    $this->supplier = Supplier::factory()->create();
});

function createBatchWithLot(?string $lotNumber): InventoryBatch
{
    return InventoryBatch::factory()->create([
        'raw_material_id' => test()->rawMaterial->id,
        'warehouse_id' => test()->warehouse->id,
        'initial_quantity' => '100',
        'remaining_quantity' => '100',
        'unit_price' => '1500',
        'entry_date' => '2026-06-15',
        'lot_number' => $lotNumber,
    ]);
}

it('generates sequential lot numbers per year and ignores other years and manual codes', function (): void {
    $this->travelTo('2026-06-15 15:00:00');
    $generator = app(RawMaterialLotNumberGenerator::class);

    expect(DB::transaction(fn () => $generator->next()))->toBe('MP-2026-0001');

    createBatchWithLot('LOTE-2026-9999');
    createBatchWithLot('MP-2026-ABC');
    createBatchWithLot('MP-2025-0050');
    createBatchWithLot('MP-2026-0007');

    expect(DB::transaction(fn () => $generator->next()))->toBe('MP-2026-0008');

    $this->travelTo('2027-01-10 15:00:00');

    expect(DB::transaction(fn () => $generator->next()))->toBe('MP-2027-0001');
});

it('uses the plant timezone year when generating the lot number on New Year Eve', function (): void {
    // 2027-01-01 03:00 UTC son las 22:00 del 2026-12-31 en Bogotá (UTC-5).
    $this->travelTo('2027-01-01 03:00:00');
    $generator = app(RawMaterialLotNumberGenerator::class);

    expect(DB::transaction(fn () => $generator->next()))->toBe('MP-2026-0001');
});

it('assigns an automatic lot number and flashes it when an entry creates a new lot', function (): void {
    $this->travelTo('2026-06-15 15:00:00');

    $this->post(route('inventory-movements.store'), [
        'raw_material_id' => $this->rawMaterial->id,
        'warehouse_id' => $this->warehouse->id,
        'batch_id' => null,
        'type' => 'entry',
        'quantity' => '25',
        'cost_price' => '1500',
        'supplier_id' => $this->supplier->id,
        'movement_date' => '2026-06-15',
    ])
        ->assertRedirect(route('inventory-movements.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Entrada registrada en el lote MP-2026-0001.');

    expect(InventoryBatch::query()->sole()->lot_number)->toBe('MP-2026-0001');
});

it('keeps the existing lot number and flashes the standard message for an entry to an existing lot or an exit', function (): void {
    $this->travelTo('2026-06-15 15:00:00');
    $batch = createBatchWithLot('LOTE-VIEJO-01');

    $this->post(route('inventory-movements.store'), [
        'raw_material_id' => $this->rawMaterial->id,
        'warehouse_id' => $this->warehouse->id,
        'batch_id' => $batch->id,
        'type' => 'entry',
        'quantity' => '10',
        'cost_price' => '1500',
        'movement_date' => '2026-06-15',
    ])
        ->assertRedirect(route('inventory-movements.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Movimiento de inventario registrado exitosamente.');

    expect($batch->fresh()->lot_number)->toBe('LOTE-VIEJO-01');

    $this->post(route('inventory-movements.store'), [
        'raw_material_id' => $this->rawMaterial->id,
        'warehouse_id' => $this->warehouse->id,
        'batch_id' => $batch->id,
        'type' => 'exit',
        'quantity' => '5',
        'movement_date' => '2026-06-15',
    ])
        ->assertRedirect(route('inventory-movements.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Movimiento de inventario registrado exitosamente.');

    expect($batch->fresh()->lot_number)->toBe('LOTE-VIEJO-01');
});

it('rejects lot_number with 422 when sent in the movement request', function (): void {
    $this->postJson(route('inventory-movements.store'), [
        'raw_material_id' => $this->rawMaterial->id,
        'warehouse_id' => $this->warehouse->id,
        'batch_id' => null,
        'type' => 'entry',
        'quantity' => '10',
        'cost_price' => '1500',
        'lot_number' => 'MP-2026-0001',
        'supplier_id' => $this->supplier->id,
        'movement_date' => plantToday(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lot_number']);
});

it('allows duplicate manual lot numbers without MP- prefix and rejects duplicate MP- lot numbers at the database level', function (): void {
    createBatchWithLot('LOTE-MANUAL-1');
    createBatchWithLot('LOTE-MANUAL-1');

    expect(InventoryBatch::query()->where('lot_number', 'LOTE-MANUAL-1')->count())->toBe(2);

    createBatchWithLot('MP-2026-0001');

    expect(fn () => createBatchWithLot('MP-2026-0001'))
        ->toThrow(UniqueConstraintViolationException::class);
});

