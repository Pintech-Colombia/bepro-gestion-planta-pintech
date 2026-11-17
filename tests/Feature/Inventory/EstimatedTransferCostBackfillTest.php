<?php

declare(strict_types=1);

use App\Enums\FinishedInventoryMovementReason;
use App\Enums\InventoryMovementType;
use App\Models\FinishedInventoryMovement;
use App\Models\FinishedProductBatch;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinishedInventory\EstimatedTransferCostBackfill;

// Relleno del costo de traslado de los lotes de PT anteriores a la columna: costo de su entrada «Producción» con el CIF
// actual del producto, marcado como estimado.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->warehouse = Warehouse::factory()->create();
});

function legacyBatch(Product $product): FinishedProductBatch
{
    return FinishedProductBatch::create([
        'product_id' => $product->id,
        'production_order_id' => ProductionOrder::factory()->create(['product_id' => $product->id])->id,
        'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
        'initial_quantity' => '10',
        'entry_date' => now()->toDateString(),
    ]);
}

function legacyEntry(FinishedProductBatch $batch, ?string $costPrice, FinishedInventoryMovementReason $reason = FinishedInventoryMovementReason::Production): void
{
    FinishedInventoryMovement::create([
        'product_id' => $batch->product_id,
        'product_variant_id' => $batch->product_variant_id,
        'warehouse_id' => test()->warehouse->id,
        'finished_product_batch_id' => $batch->id,
        'type' => InventoryMovementType::Entry,
        'reason' => $reason,
        'quantity' => '10',
        'cost_price' => $costPrice,
        'movement_date' => now()->toDateString(),
        'created_by' => test()->user->id,
    ]);
}

it('estima el costo con el CIF actual del producto y deja nulo el lote sin entrada de producción', function () {
    $withCif = legacyBatch(Product::factory()->create(['cif_percentage' => '20']));
    legacyEntry($withCif, '10000');

    $withoutCif = legacyBatch(Product::factory()->create(['cif_percentage' => null]));
    legacyEntry($withoutCif, '850.5');

    $withoutEntry = legacyBatch(Product::factory()->create(['cif_percentage' => '20']));

    // Una entrada que no es de producción (devolución) no cuenta, ni una de producción sin costo.
    $onlyReturn = legacyBatch(Product::factory()->create(['cif_percentage' => '20']));
    legacyEntry($onlyReturn, '500', FinishedInventoryMovementReason::Return);
    $entryWithoutCost = legacyBatch(Product::factory()->create(['cif_percentage' => '20']));
    legacyEntry($entryWithoutCost, null);

    expect(app(EstimatedTransferCostBackfill::class)->run())->toBe(2)
        ->and($withCif->fresh())
        ->transfer_unit_cost->toBe('12000.0000')
        ->transfer_cost_estimated->toBeTrue()
        ->and($withoutCif->fresh())
        ->transfer_unit_cost->toBe('850.5000')
        ->transfer_cost_estimated->toBeTrue();

    foreach ([$withoutEntry, $onlyReturn, $entryWithoutCost] as $batch) {
        expect($batch->fresh())
            ->transfer_unit_cost->toBeNull()
            ->transfer_cost_estimated->toBeFalse();
    }
});

it('no toca los lotes que ya tienen costo de traslado y correrlo otra vez no cambia nada', function () {
    $product = Product::factory()->create(['cif_percentage' => '20']);
    $completed = legacyBatch($product);
    legacyEntry($completed, '10000');
    $completed->update(['transfer_unit_cost' => '11500', 'transfer_cost_estimated' => false]);

    $legacy = legacyBatch($product);
    legacyEntry($legacy, '100');

    expect(app(EstimatedTransferCostBackfill::class)->run())->toBe(1);

    // El CIF cambia después: una segunda corrida no vuelve a estimar lo ya estimado.
    $product->update(['cif_percentage' => '50']);

    expect(app(EstimatedTransferCostBackfill::class)->run())->toBe(0)
        ->and($completed->fresh())
        ->transfer_unit_cost->toBe('11500.0000')
        ->transfer_cost_estimated->toBeFalse()
        ->and($legacy->fresh()->transfer_unit_cost)->toBe('120.0000');
});

it('toma la primera entrada de producción del lote', function () {
    $batch = legacyBatch(Product::factory()->create(['cif_percentage' => '10']));
    legacyEntry($batch, '1000');
    legacyEntry($batch, '9999');

    app(EstimatedTransferCostBackfill::class)->run();

    expect($batch->fresh()->transfer_unit_cost)->toBe('1100.0000');
});

it('la migración llama al relleno', function () {
    $batch = legacyBatch(Product::factory()->create(['cif_percentage' => '20']));
    legacyEntry($batch, '10000');

    (require database_path('migrations/2026_11_17_110001_backfill_estimated_transfer_cost_on_finished_product_batches.php'))->up();

    expect($batch->fresh())
        ->transfer_unit_cost->toBe('12000.0000')
        ->transfer_cost_estimated->toBeTrue();
});
