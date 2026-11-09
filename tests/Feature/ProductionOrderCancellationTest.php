<?php

declare(strict_types=1);

use App\Actions\Production\PlanRemnantConsumptionAction;
use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\Formula;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AvailableRemnantsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $category = ProductCategory::create(['name' => 'General']);
    $unit = UnitOfMeasure::create(['code' => 'GAL', 'name' => 'Galón', 'symbol' => 'gal']);

    $product = Product::create([
        'name' => 'Producto Cancelable',
        'category_id' => $category->id,
        'unit_of_measure_id' => $unit->id,
        'current_cost' => 10,
        'cif_percentage' => 20,
        'current_price' => 12,
        'price_threshold' => 5,
    ]);

    $this->formula = Formula::create([
        'product_id' => $product->id,
        'version' => 1,
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'Planta Cancelaciones',
        'city' => 'Cali',
        'type' => 'factory',
        'is_active' => true,
    ]);
});

function makeOrderForCancellationTest(object $context, ProductionOrderStatus $status, ?string $notes = null): ProductionOrder
{
    return ProductionOrder::create([
        'order_number' => 'OP-CAN-'.fake()->unique()->numerify('####'),
        'lot_number' => fake()->unique()->numberBetween(100000, 999999),
        'product_id' => $context->formula->product_id,
        'formula_id' => $context->formula->id,
        'warehouse_id' => $context->warehouse->id,
        'quantity' => 20,
        'status' => $status,
        'planned_date' => now()->addDay(),
        'notes' => $notes,
        'created_by' => $context->user->id,
    ]);
}

test('it cancels a pending production order and stores the reason', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::Pending, 'Nota original');

    $response = $this->from(route('production-orders.show', $order))
        ->post(route('production-orders.cancel', $order), [
            'reason' => 'Cliente pausó el pedido',
        ]);

    $response->assertRedirect(route('production-orders.show', $order));
    $response->assertInertiaFlash('success', 'Orden de producción cancelada con éxito.');

    $order->refresh();
    expect($order->status)->toBe(ProductionOrderStatus::Cancelled);
    expect($order->notes)->toContain('Nota original');
    expect($order->notes)->toContain('Cancelación: Cliente pausó el pedido');
});

test('it cancels an in progress production order with a reason', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::InProgress);

    $response = $this->from(route('production-orders.show', $order))
        ->post(route('production-orders.cancel', $order), ['reason' => 'Se canceló la venta que la pedía']);

    $response->assertRedirect(route('production-orders.show', $order));

    $order->refresh();
    expect($order->status)->toBe(ProductionOrderStatus::Cancelled)
        ->and($order->notes)->toBe('Cancelación: Se canceló la venta que la pedía');
});

test('it rejects cancelling a completed production order', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::Completed);

    $response = $this->from(route('production-orders.show', $order))
        ->post(route('production-orders.cancel', $order), [
            'reason' => 'Ya no aplica',
        ]);

    $response->assertRedirect(route('production-orders.show', $order));
    $response->assertInertiaFlash('error', "No se puede cancelar una orden en estado 'Completada'.");

    $order->refresh();
    expect($order->status)->toBe(ProductionOrderStatus::Completed);
});

// Auditoría 3.6, PR 10 (D-OPX2, P10-3): botón «Cancelar orden» en el detalle, con motivo obligatorio.

it('requires a reason of at least 10 characters to cancel', function (?string $reason) {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::Pending);

    $this->post(route('production-orders.cancel', $order), ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Pending);
})->with([null, '', 'corto', '         x', str_repeat('a', 1001)]);

it('tells the page whether the order can be cancelled', function (ProductionOrderStatus $status, bool $expected) {
    $order = makeOrderForCancellationTest($this, $status);

    $this->get(route('production-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('can.cancel', $expected));
})->with([
    'pendiente' => [ProductionOrderStatus::Pending, true],
    'en curso' => [ProductionOrderStatus::InProgress, true],
    'en revisión' => [ProductionOrderStatus::PendingReview, false],
    'completada' => [ProductionOrderStatus::Completed, false],
    'cancelada' => [ProductionOrderStatus::Cancelled, false],
]);

it('does not offer cancelling to a role without the cancel permission', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::Pending);
    $production = User::factory()->create(['email_verified_at' => now()]);
    $production->assignRole(SystemRole::Production->value);

    $this->actingAs($production)->get(route('production-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('can.cancel', false));

    $this->actingAs($production)->post(route('production-orders.cancel', $order), ['reason' => 'Intento sin permiso'])
        ->assertForbidden();
});

it('answers a lost race with an error the dialog can show, not only a flash', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::Completed);

    $this->from(route('production-orders.show', $order))
        ->post(route('production-orders.cancel', $order), ['reason' => 'La orden ya se había completado'])
        ->assertRedirect(route('production-orders.show', $order))
        ->assertSessionHasErrors(['production_order' => "No se puede cancelar una orden en estado 'Completada'."])
        ->assertInertiaFlash('error');
});

it('cancelling an order with planned remnants leaves the remnant untouched, frees it and keeps the lines', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::Pending);
    $source = makeOrderForCancellationTest($this, ProductionOrderStatus::Completed);
    $remnant = cancellationTestRemnant($this, $source);
    app(PlanRemnantConsumptionAction::class)->execute($remnant, $order, '12.5', $this->user);

    $this->post(route('production-orders.cancel', $order), ['reason' => 'No es urgente, se hará más adelante'])
        ->assertSessionHasNoErrors();

    $order->refresh();
    $remnant->refresh();
    expect($order->status)->toBe(ProductionOrderStatus::Cancelled)
        ->and($remnant->available_quantity_kg)->toBe('50.0000')
        ->and($remnant->available_quantity_gallons)->toBe('10.0000')
        ->and($remnant->status)->toBe(RemnantStatus::Available)
        // La línea queda como historial, sin aplicar y sin reservar: lo libre vuelve a ser todo el saldo.
        ->and($order->remnantConsumptions()->planned()->count())->toBe(1)
        ->and(app(AvailableRemnantsService::class)->freeKilos($remnant))->toBe('50.0000')
        ->and($order->notes)->toContain('Cancelación: No es urgente, se hará más adelante')
        ->and($order->notes)->not->toContain('Saldos consumidos');
});

it('has the cancellable statuses in one place', function () {
    expect(ProductionOrderStatus::cancellable())->toBe([ProductionOrderStatus::Pending, ProductionOrderStatus::InProgress]);
});

it('lists the remnants already drawn down (before planning existed) in the cancellation note, in order', function () {
    $order = makeOrderForCancellationTest($this, ProductionOrderStatus::InProgress);
    $firstSource = makeOrderForCancellationTest($this, ProductionOrderStatus::Completed);
    $secondSource = makeOrderForCancellationTest($this, ProductionOrderStatus::Completed);
    $first = cancellationTestRemnant($this, $firstSource);
    $second = cancellationTestRemnant($this, $secondSource);
    // Filas registradas antes de los saldos planeados: ya se habían descontado (la migración les puso `applied_at`).
    $applied = fn (ProductionRemnant $remnant, string $kilos) => RemnantConsumption::create([
        'remnant_id' => $remnant->id, 'target_order_id' => $order->id, 'quantity_kg' => $kilos, 'quantity_gallons' => '1',
        'consumed_by' => $this->user->id, 'consumed_at' => now(), 'applied_at' => now(),
    ]);
    $firstConsumption = $applied($first, '5');
    $applied($second, '10');
    // Una planeada no va en la nota: no se había descontado.
    app(PlanRemnantConsumptionAction::class)->execute($first, $order, '3', $this->user);
    // Un UPDATE mueve la fila: sin ORDER BY, PostgreSQL la devuelve al final en un barrido secuencial.
    $firstConsumption->update(['notes' => 'reubicada']);

    $this->post(route('production-orders.cancel', $order), ['reason' => 'Ya no se va a producir'])->assertSessionHasNoErrors();

    expect($order->fresh()->notes)->toContain("Saldos consumidos que no vuelven al inventario: {$firstSource->order_number} 5 kg; {$secondSource->order_number} 10 kg.");
});

function cancellationTestRemnant(object $context, ProductionOrder $source): ProductionRemnant
{
    return ProductionRemnant::create([
        'source_order_id' => $source->id, 'product_id' => $context->formula->product_id,
        'warehouse_id' => $context->warehouse->id, 'original_quantity_gallons' => 10, 'original_quantity_kg' => 50,
        'available_quantity_gallons' => 10, 'available_quantity_kg' => 50, 'density_kg_per_gallon' => 5,
        'cost_per_gallon' => 5.5, 'status' => RemnantStatus::Available, 'created_by' => $context->user->id,
    ]);
}
