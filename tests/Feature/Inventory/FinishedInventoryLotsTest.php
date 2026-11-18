<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\FinishedInventory;
use App\Models\FinishedProductBatch;
use App\Models\FinishedProductBatchStock;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

// Inventario de PT por lote: cada fila (presentación y bodega) trae sus lotes con stock, del más antiguo al más nuevo,
// en una sola consulta. El costo de traslado solo viaja con costs.view.

beforeEach(function (): void {
    $this->admin = userWithRole(SystemRole::Admin);
    $this->warehouse = Warehouse::factory()->storage()->create(['name' => 'Bodega Norte']);
    $this->otherWarehouse = Warehouse::factory()->storage()->create(['name' => 'Bodega Sur']);
    $this->product = Product::factory()->create(['name' => 'Esmalte PT']);
    $this->gallon = ProductVariant::factory()->create([
        'product_id' => $this->product->id,
        'presentation_value' => 1,
        'presentation_label' => 'Galón',
    ]);
});

/**
 * Un lote de una OP propia con stock en la bodega dada.
 */
function finishedLot(ProductVariant $variant, Warehouse $warehouse, int $quantity, string $entryDate, array $batch = [], array $order = []): FinishedProductBatch
{
    $order = ProductionOrder::factory()->create([
        'product_id' => $variant->product_id,
        'created_by' => test()->admin->id,
        ...$order,
    ]);

    $lot = FinishedProductBatch::create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'production_order_id' => $order->id,
        'initial_quantity' => max($quantity, 1),
        'entry_date' => $entryDate,
        ...$batch,
    ]);

    FinishedProductBatchStock::create([
        'finished_product_batch_id' => $lot->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => $quantity,
    ]);

    return $lot;
}

function finishedRow(ProductVariant $variant, Warehouse $warehouse, int $quantity): FinishedInventory
{
    return FinishedInventory::create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => $quantity,
    ]);
}

it('despliega los dos lotes de una presentación en la bodega, del más antiguo al más nuevo, y suman la fila', function (): void {
    // Creado primero el nuevo: el orden sale de la fecha de entrada, no del id.
    $newer = finishedLot($this->gallon, $this->warehouse, 20, '2026-11-10');
    $older = finishedLot($this->gallon, $this->warehouse, 30, '2026-10-01');
    finishedRow($this->gallon, $this->warehouse, 50);

    $this->actingAs($this->admin)
        ->get(route('finished-inventory.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Inventory/FinishedInventory/Index')
            ->has('inventory.data', 1)
            ->where('inventory.data.0.quantity', 50)
            ->has('inventory.data.0.lots', 2)
            ->where('inventory.data.0.lots.0.lot_number', $older->productionOrder->lot_number)
            ->where('inventory.data.0.lots.0.production_order.id', $older->production_order_id)
            ->where('inventory.data.0.lots.0.production_order.order_number', $older->productionOrder->order_number)
            ->where('inventory.data.0.lots.0.entry_date', '2026-10-01')
            ->where('inventory.data.0.lots.0.quantity', 30)
            ->where('inventory.data.0.lots.1.lot_number', $newer->productionOrder->lot_number)
            ->where('inventory.data.0.lots.1.quantity', 20)
            ->where('can.viewProductionOrders', true)
        );
});

it('muestra el color de la OP de cada lote, y nada si la OP no tiene color', function (): void {
    // Un producto genérico a pedido: la misma fila junta lotes de colores distintos.
    finishedLot($this->gallon, $this->warehouse, 12, '2026-10-01');
    finishedLot($this->gallon, $this->warehouse, 8, '2026-11-01', order: ['color' => 'RAL 7016']);
    finishedRow($this->gallon, $this->warehouse, 20);

    $this->actingAs($this->admin)
        ->get(route('finished-inventory.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('inventory.data.0.lots', 2)
            ->where('inventory.data.0.lots.0.color', null)
            ->where('inventory.data.0.lots.1.color', 'RAL 7016')
        );
});

it('ordena por id los lotes que entraron el mismo día', function (): void {
    $first = finishedLot($this->gallon, $this->warehouse, 5, '2026-11-01');
    $second = finishedLot($this->gallon, $this->warehouse, 7, '2026-11-01');
    finishedRow($this->gallon, $this->warehouse, 12);

    $this->actingAs($this->admin)
        ->get(route('finished-inventory.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inventory.data.0.lots.0.lot_number', $first->productionOrder->lot_number)
            ->where('inventory.data.0.lots.1.lot_number', $second->productionOrder->lot_number)
        );
});

it('no despliega los lotes de otra bodega ni los que ya no tienen stock', function (): void {
    $here = finishedLot($this->gallon, $this->warehouse, 10, '2026-10-01');
    finishedLot($this->gallon, $this->warehouse, 0, '2026-10-05');
    $there = finishedLot($this->gallon, $this->otherWarehouse, 4, '2026-10-02');
    finishedRow($this->gallon, $this->warehouse, 10);
    finishedRow($this->gallon, $this->otherWarehouse, 4);

    // Otra presentación del mismo producto en la misma bodega: sus lotes son de su propia fila.
    $quart = ProductVariant::factory()->create([
        'product_id' => $this->product->id,
        'presentation_value' => 0.25,
        'presentation_label' => 'Cuarto',
    ]);
    $quartLot = finishedLot($quart, $this->warehouse, 8, '2026-09-01');
    finishedRow($quart, $this->warehouse, 8);

    $response = $this->actingAs($this->admin)->get(route('finished-inventory.index'))->assertOk();

    $rows = collect($response->viewData('page')['props']['inventory']['data'])
        ->keyBy(fn (array $row): string => $row['variant']['id'].':'.$row['warehouse']['id']);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows["{$this->gallon->id}:{$this->warehouse->id}"]['lots'])->pluck('lot_number')->all())
        ->toBe([$here->productionOrder->lot_number])
        ->and(collect($rows["{$this->gallon->id}:{$this->otherWarehouse->id}"]['lots'])->pluck('lot_number')->all())
        ->toBe([$there->productionOrder->lot_number])
        ->and(collect($rows["{$quart->id}:{$this->warehouse->id}"]['lots'])->pluck('lot_number')->all())
        ->toBe([$quartLot->productionOrder->lot_number]);

    // La suma de los lotes de cada fila es la cantidad de la fila.
    foreach ($rows as $row) {
        expect(array_sum(array_column($row['lots'], 'quantity')))->toBe($row['quantity']);
    }
});

it('no despliega lotes de una bodega que el usuario no ve', function (): void {
    finishedLot($this->gallon, $this->warehouse, 10, '2026-10-01');
    finishedLot($this->gallon, $this->otherWarehouse, 4, '2026-10-02');
    finishedRow($this->gallon, $this->warehouse, 10);
    finishedRow($this->gallon, $this->otherWarehouse, 4);

    $commercial = userWithRole(SystemRole::Commercial);
    $commercial->warehouses()->attach($this->warehouse->id);

    $this->actingAs($commercial)
        ->get(route('finished-inventory.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('inventory.data', 1)
            ->where('inventory.data.0.warehouse.id', $this->warehouse->id)
            ->has('inventory.data.0.lots', 1)
            ->where('inventory.data.0.lots.0.quantity', 10)
        );
});

it('envía el costo de traslado con costs.view y marca el estimado', function (): void {
    finishedLot($this->gallon, $this->warehouse, 3, '2026-10-01', [
        'transfer_unit_cost' => '12000',
        'transfer_cost_estimated' => true,
    ]);
    finishedLot($this->gallon, $this->warehouse, 2, '2026-11-01', [
        'transfer_unit_cost' => '1248.9',
        'transfer_cost_estimated' => false,
    ]);
    finishedLot($this->gallon, $this->warehouse, 1, '2026-11-02');
    finishedRow($this->gallon, $this->warehouse, 6);

    $this->actingAs($this->admin)
        ->get(route('finished-inventory.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.viewCosts', true)
            ->where('inventory.data.0.lots.0.transfer_unit_cost', '12000.0000')
            ->where('inventory.data.0.lots.0.transfer_cost_estimated', true)
            ->where('inventory.data.0.lots.1.transfer_unit_cost', '1248.9000')
            ->where('inventory.data.0.lots.1.transfer_cost_estimated', false)
            // Lote sin costo (sin entrada de producción al rellenar): nulo, no cero.
            ->where('inventory.data.0.lots.2.transfer_unit_cost', null)
        );
});

it('no envía el costo de traslado sin costs.view', function (User $user): void {
    expect($user->can(Permission::CostsView->value))->toBeFalse();

    $user->warehouses()->attach($this->warehouse->id);
    finishedLot($this->gallon, $this->warehouse, 3, '2026-10-01', [
        'transfer_unit_cost' => '12000',
        'transfer_cost_estimated' => true,
    ]);
    finishedRow($this->gallon, $this->warehouse, 3);

    $response = $this->actingAs($user)
        ->get(route('finished-inventory.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.viewCosts', false)
            ->has('inventory.data.0.lots', 1)
            ->missing('inventory.data.0.lots.0.transfer_unit_cost')
            ->missing('inventory.data.0.lots.0.transfer_cost_estimated')
        );

    expect($response->getContent())->not->toContain('transfer_unit_cost')
        ->not->toContain('12000.0000');
})->with([
    'comercial' => [fn () => userWithRole(SystemRole::Commercial)],
    'solo inventario PT' => [fn () => userWithPermissions([Permission::FinishedInventoryView])],
]);

it('enlaza la OP del lote solo con production_orders.view', function (User $user, bool $expected): void {
    $user->warehouses()->attach($this->warehouse->id);
    finishedLot($this->gallon, $this->warehouse, 3, '2026-10-01');
    finishedRow($this->gallon, $this->warehouse, 3);

    expect($user->can(Permission::ProductionOrdersView->value))->toBe($expected);

    $this->actingAs($user)
        ->get(route('finished-inventory.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.viewProductionOrders', $expected)
            ->has('inventory.data.0.lots.0.production_order.order_number')
        );
})->with([
    'solo inventario PT' => [fn () => userWithPermissions([Permission::FinishedInventoryView]), false],
    'admin' => [fn () => test()->admin, true],
]);

it('trae los lotes de la página con un número fijo de consultas', function (): void {
    $countQueries = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('finished-inventory.index'))->assertOk();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    };

    finishedLot($this->gallon, $this->warehouse, 2, '2026-10-01');
    finishedRow($this->gallon, $this->warehouse, 2);

    // Primera visita aparte: la caché de permisos y de la sesión no entra en la cuenta.
    $this->actingAs($this->admin)->get(route('finished-inventory.index'))->assertOk();

    $oneRow = count($countQueries());

    // 14 filas más, cada una con dos lotes: 15 en la página.
    foreach (range(1, 14) as $i) {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create()->id]);
        finishedLot($variant, $this->warehouse, 1, '2026-10-01');
        finishedLot($variant, $this->warehouse, 2, '2026-10-02');
        finishedRow($variant, $this->warehouse, 3);
    }

    $fifteenRows = $countQueries();

    $response = $this->actingAs($this->admin)->get(route('finished-inventory.index'));
    $rows = $response->viewData('page')['props']['inventory']['data'];

    expect($rows)->toHaveCount(15)
        ->and(count($fifteenRows))->toBe($oneRow)
        ->and(collect($fifteenRows)->filter(fn (string $sql): bool => str_contains($sql, 'finished_product_batch_stocks'))->count())->toBe(1);
});
