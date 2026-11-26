<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\InventoryBatch;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = actingAsRole(SystemRole::Admin, ['email_verified_at' => now()]);
    $this->warehouse = Warehouse::factory()->factory()->create();
    $this->supplier = Supplier::factory()->create(['name' => 'Resinas del Sur']);
    $this->rawMaterial = RawMaterial::factory()->create(['tracks_inventory' => true]);
});

/**
 * Crea un lote de inventario para la MP de la prueba con atributos opcionales.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeShowBatch(array $attributes = []): InventoryBatch
{
    return InventoryBatch::factory()->create([
        'raw_material_id' => test()->rawMaterial->id,
        'warehouse_id' => test()->warehouse->id,
        'supplier_id' => test()->supplier->id,
        'initial_quantity' => '100.0000',
        'remaining_quantity' => '50.0000',
        'unit_price' => '2500.0000',
        'entry_date' => '2026-10-10',
        ...$attributes,
    ]);
}

it('lists active batches in FIFO order with id tiebreaker, excludes exhausted ones, and keeps history null by default', function (): void {
    $newest = makeShowBatch(['lot_number' => 'L-NEW', 'entry_date' => '2026-10-20', 'remaining_quantity' => '25.0000']);
    $sameDayFirst = makeShowBatch(['lot_number' => 'L-DAY-1', 'entry_date' => '2026-10-05', 'remaining_quantity' => '10.0000']);
    $sameDaySecond = makeShowBatch(['lot_number' => 'L-DAY-2', 'entry_date' => '2026-10-05', 'remaining_quantity' => '15.0000']);
    makeShowBatch(['lot_number' => 'L-EXHAUSTED', 'entry_date' => '2026-10-01', 'remaining_quantity' => '0.0000']);

    $this->get(route('raw-materials.show', $this->rawMaterial))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/RawMaterials/Show')
            ->where('batchesTab', 'existencia')
            ->where('activeBatchCount', 3)
            ->where('exhaustedBatchCount', 1)
            ->where('exhaustedBatches', null)
            ->where('hasAvailableStock', true)
            ->where('activeBatches', fn ($batches) => collect($batches)->pluck('id')->all() === [
                $sameDayFirst->id,
                $sameDaySecond->id,
                $newest->id,
            ]));
});

it('paginates exhausted batches newest first with id tiebreaker only when historial tab is requested', function (): void {
    // Un lote activo que nunca debe aparecer en el historial.
    makeShowBatch(['lot_number' => 'L-ACTIVE', 'entry_date' => '2026-11-25', 'remaining_quantity' => '12.0000']);

    // 16 lotes agotados: dos el último día para validar el desempate por id descendente.
    $oldest = makeShowBatch(['lot_number' => 'L-OLD-00', 'entry_date' => '2026-09-01', 'remaining_quantity' => '0.0000']);

    foreach (range(1, 13) as $day) {
        makeShowBatch([
            'lot_number' => sprintf('L-HIST-%02d', $day),
            'entry_date' => sprintf('2026-10-%02d', $day),
            'remaining_quantity' => '0.0000',
        ]);
    }

    $sameDayFirst = makeShowBatch(['lot_number' => 'L-TOP-1', 'entry_date' => '2026-11-01', 'remaining_quantity' => '0.0000']);
    $sameDaySecond = makeShowBatch(['lot_number' => 'L-TOP-2', 'entry_date' => '2026-11-01', 'remaining_quantity' => '0.0000']);

    $this->get(route('raw-materials.show', [
        'raw_material' => $this->rawMaterial,
        'lotes' => 'historial',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batchesTab', 'historial')
            ->where('activeBatchCount', 1)
            ->where('exhaustedBatchCount', 16)
            ->where('exhaustedBatches.total', 16)
            ->where('exhaustedBatches.per_page', 15)
            ->where('exhaustedBatches.current_page', 1)
            ->has('exhaustedBatches.data', 15)
            ->where('exhaustedBatches.data.0.id', $sameDaySecond->id)
            ->where('exhaustedBatches.data.1.id', $sameDayFirst->id));

    $this->get(route('raw-materials.show', [
        'raw_material' => $this->rawMaterial,
        'lotes' => 'historial',
        'lotes_page' => 2,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batchesTab', 'historial')
            ->where('exhaustedBatches.current_page', 2)
            ->has('exhaustedBatches.data', 1)
            ->where('exhaustedBatches.data.0.id', $oldest->id));
});

it('computes total available quantity exactly on the server without float drift', function (): void {
    makeShowBatch(['lot_number' => 'L-01', 'initial_quantity' => '1.0000', 'remaining_quantity' => '0.1000', 'entry_date' => '2026-10-01']);
    makeShowBatch(['lot_number' => 'L-02', 'initial_quantity' => '1.0000', 'remaining_quantity' => '0.2000', 'entry_date' => '2026-10-02']);
    makeShowBatch(['lot_number' => 'L-EXH', 'initial_quantity' => '5.0000', 'remaining_quantity' => '0.0000', 'entry_date' => '2026-10-03']);

    $this->get(route('raw-materials.show', $this->rawMaterial))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalAvailableQuantity', '0.3000')
            ->where('hasAvailableStock', true));
});

it('marks consumes_first only on the first FIFO batch of each warehouse', function (): void {
    $otherWarehouse = Warehouse::factory()->factory()->create(['name' => 'Planta 2']);

    $firstInMain = makeShowBatch(['warehouse_id' => $this->warehouse->id, 'lot_number' => 'W1-FIRST', 'entry_date' => '2026-10-01']);
    $secondInMain = makeShowBatch(['warehouse_id' => $this->warehouse->id, 'lot_number' => 'W1-SECOND', 'entry_date' => '2026-10-05']);
    $firstInOther = makeShowBatch(['warehouse_id' => $otherWarehouse->id, 'lot_number' => 'W2-FIRST', 'entry_date' => '2026-10-02']);
    $secondInOther = makeShowBatch(['warehouse_id' => $otherWarehouse->id, 'lot_number' => 'W2-SECOND', 'entry_date' => '2026-10-06']);

    $this->get(route('raw-materials.show', $this->rawMaterial))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeBatches', fn ($batches) => collect($batches)
                ->mapWithKeys(fn (array $batch): array => [$batch['id'] => $batch['consumes_first']])
                ->all() === [
                    $firstInMain->id => true,
                    $firstInOther->id => true,
                    $secondInMain->id => false,
                    $secondInOther->id => false,
                ]));
});

it('hides unit_price in both active and exhausted batch lists for users without costs.view', function (): void {
    makeShowBatch(['lot_number' => 'L-ACT', 'remaining_quantity' => '20.0000', 'unit_price' => '3200.5000']);
    makeShowBatch(['lot_number' => 'L-EXH', 'remaining_quantity' => '0.0000', 'unit_price' => '2900.2500']);

    actingAsRole(SystemRole::Production, ['email_verified_at' => now()]);

    $this->get(route('raw-materials.show', [
        'raw_material' => $this->rawMaterial,
        'lotes' => 'historial',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewCosts', false)
            ->where('activeBatches.0.lot_number', 'L-ACT')
            ->missing('activeBatches.0.unit_price')
            ->where('exhaustedBatches.data.0.lot_number', 'L-EXH')
            ->missing('exhaustedBatches.data.0.unit_price'));

    actingAsRole(SystemRole::Admin, ['email_verified_at' => now()]);

    $this->get(route('raw-materials.show', [
        'raw_material' => $this->rawMaterial,
        'lotes' => 'historial',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewCosts', true)
            ->where('activeBatches.0.unit_price', '3200.5000')
            ->where('exhaustedBatches.data.0.unit_price', '2900.2500'));
});

it('validates lotes and lotes_page query parameters', function (array $query, string $errorKey): void {
    $this->get(route('raw-materials.show', [
        'raw_material' => $this->rawMaterial,
        ...$query,
    ]))->assertSessionHasErrors($errorKey);
})->with([
    'pestaña desconocida' => [['lotes' => 'otra'], 'lotes'],
    'página cero' => [['lotes_page' => 0], 'lotes_page'],
    'página no numérica' => [['lotes_page' => 'abc'], 'lotes_page'],
]);

it('handles a raw material without inventory tracking and without batches', function (): void {
    $nonTracked = RawMaterial::factory()->create(['tracks_inventory' => false]);

    $this->get(route('raw-materials.show', $nonTracked))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rawMaterial.tracks_inventory', false)
            ->where('activeBatches', [])
            ->where('exhaustedBatches', null)
            ->where('activeBatchCount', 0)
            ->where('exhaustedBatchCount', 0)
            ->where('totalAvailableQuantity', '0')
            ->where('hasAvailableStock', false));
});

it('preserves returnTo when switching to the historial tab', function (): void {
    $this->get(route('raw-materials.show', [
        'raw_material' => $this->rawMaterial,
        'lotes' => 'historial',
        'return_to' => '/raw-materials?search=MP-01',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batchesTab', 'historial')
            ->where('returnTo', '/raw-materials?search=MP-01'));
});
