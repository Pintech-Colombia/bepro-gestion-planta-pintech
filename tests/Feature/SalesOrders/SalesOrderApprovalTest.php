<?php

declare(strict_types=1);

use App\Actions\SalesOrders\HoldSalesOrderAction;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SystemRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Notifications\SalesOrders\SalesOrderApproved;
use App\Notifications\SalesOrders\SalesOrderAwaitingApproval;
use App\Notifications\SalesOrders\SalesOrderOnHold;
use App\Notifications\SalesOrders\SalesOrderReleased;
use App\Services\AssignableRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Aprobación de pedidos: todo pedido nace «Por aprobar»; la coordinación comercial (o Admin) lo aprueba, total o parcial,
 * o lo retiene con un motivo. Cada paso deja un aviso personal en la campana.
 */
beforeEach(function (): void {
    $this->coordinator = userWithRole(SystemRole::CommercialCoordination);
    $this->admin = userWithRole(SystemRole::Admin);
    $this->production = userWithRole(SystemRole::Production);
    $this->commercial = userWithRole(SystemRole::Commercial);
    $this->superAdmin = userWithRole(SystemRole::SuperAdmin);
});

/**
 * Presentación activa para las líneas de un pedido.
 */
function approvalTestVariant(): ProductVariant
{
    $category = ProductCategory::create(['name' => 'Categoría de aprobación']);
    $unit = UnitOfMeasure::create(['code' => 'UA', 'name' => 'Unidad', 'symbol' => 'u']);
    $product = Product::create([
        'name' => 'Esmalte de aprobación',
        'category_id' => $category->id,
        'unit_of_measure_id' => $unit->id,
        'is_active' => true,
    ]);

    return ProductVariant::create([
        'product_id' => $product->id,
        'code' => 'APR-001',
        'name' => 'Galón',
        'unit_of_measure_id' => $unit->id,
        'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function approvalOrderPayload(Client $client, ProductVariant $variant): array
{
    return [
        'client_id' => $client->id,
        'priority' => 'medium',
        'shipping_address' => 'Calle 1 # 2-3, Cali',
        'required_date' => now()->addDays(5)->format('Y-m-d'),
        'items' => [['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'quantity' => 4]],
    ];
}

function orderFor(User $creator, SalesOrderStatus $status, array $attributes = []): SalesOrder
{
    $client = Client::factory()->create(['business_name' => 'Pinturas Andinas']);

    return SalesOrder::factory()->create([
        'client_id' => $client->id,
        'client_business_name' => 'Pinturas Andinas',
        'created_by' => $creator->id,
        'status' => $status->value,
        ...$attributes,
    ]);
}

/**
 * Ids de los usuarios que recibieron un aviso de esa clase.
 *
 * @param  class-string  $type
 * @return list<int>
 */
function notifiedUserIds(string $type): array
{
    return DatabaseNotification::query()
        ->where('type', $type)
        ->orderBy('notifiable_id')
        ->pluck('notifiable_id')
        ->map(fn ($id): int => (int) $id)
        ->all();
}

// Creación

it('crea por aprobar un pedido directo y avisa a la coordinación y a SuperAdmin, no a Admin', function () {
    $variant = approvalTestVariant();
    $client = Client::factory()->create(['business_name' => 'Ferretería Norte']);

    $this->actingAs($this->commercial)
        ->post(route('sales-orders.store'), approvalOrderPayload($client, $variant))
        ->assertRedirect()
        ->assertInertiaFlash('success', 'Pedido creado. Queda por aprobar.');

    $order = SalesOrder::query()->latest('id')->firstOrFail();

    expect($order->status)->toBe(SalesOrderStatus::PendingApproval)
        ->and(notifiedUserIds(SalesOrderAwaitingApproval::class))->toEqualCanonicalizing([$this->coordinator->id, $this->superAdmin->id]);

    $notification = $this->coordinator->notifications()->sole();
    expect($notification->data)->toBe([
        'sales_order_id' => $order->id,
        'kind' => 'awaiting_approval',
        'message' => "Pedido #{$order->id} de Ferretería Norte por aprobar",
    ]);
});

it('no le avisa a la coordinación del pedido que ella misma crea', function () {
    $variant = approvalTestVariant();

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.store'), approvalOrderPayload(Client::factory()->create(), $variant))
        ->assertRedirect();

    expect(notifiedUserIds(SalesOrderAwaitingApproval::class))->toBe([$this->superAdmin->id]);
});

it('crea por aprobar el pedido que viene de una cotización y avisa a la coordinación', function () {
    $variant = approvalTestVariant();
    $quotation = Quotation::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'created_by' => $this->commercial->id,
        'status' => QuotationStatus::Accepted,
    ]);
    $quotation->items()->create([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'type' => 'primer',
        'quantity' => 2,
        'list_unit_price' => 1000,
        'price_adjustment_pct' => 0,
        'unit_price' => 1000,
        'subtotal' => 2000,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->commercial)
        ->post(route('quotations.convert-to-order', $quotation), [
            'priority' => 'high',
            'shipping_address' => 'Calle 1 # 2-3, Cali',
            'required_date' => now()->addDays(5)->format('Y-m-d'),
        ])
        ->assertRedirect()
        ->assertInertiaFlash('success', 'Cotización convertida en pedido. Queda por aprobar.');

    $order = SalesOrder::query()->latest('id')->firstOrFail();

    expect($order->status)->toBe(SalesOrderStatus::PendingApproval)
        ->and(notifiedUserIds(SalesOrderAwaitingApproval::class))->toContain($this->coordinator->id)
        ->not->toContain($this->admin->id);
});

it('nace por aprobar también si se inserta sin estado (default de la columna)', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::Pending);
    DB::table('sales_orders')->insert([
        'client_id' => $order->client_id,
        'priority' => 'medium',
        'created_by' => $this->commercial->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('sales_orders')->latest('id')->value('status'))->toBe('pending_approval');
});

// En PostgreSQL el CHECK se reescribió a mano con la lista nueva; en SQLite lo reconstruye `->change()`.
it('rechaza en la base un estado que no está en la lista', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    // Dentro de un savepoint: en PostgreSQL el error aborta la transacción que envuelve la prueba.
    expect(fn () => DB::transaction(fn () => DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'approved'])))
        ->toThrow(QueryException::class);

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'on_hold']);
    expect($order->fresh()->status)->toBe(SalesOrderStatus::OnHold);
});

// Aprobar

it('no deja aprobar sin el permiso', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->commercial)->post(route('sales-orders.approve', $order))->assertForbidden();
    $this->actingAs($this->production)->post(route('sales-orders.approve', $order))->assertForbidden();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval);
});

it('no deja aprobar un pedido que no está por aprobar ni retenido', function (SalesOrderStatus $status) {
    $order = orderFor($this->commercial, $status);

    $this->actingAs($this->coordinator)->post(route('sales-orders.approve', $order))->assertForbidden();
})->with([SalesOrderStatus::Pending, SalesOrderStatus::InProgress, SalesOrderStatus::Cancelled]);

it('exige la nota en una aprobación parcial', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), ['is_partial' => true, 'note' => ''])
        ->assertSessionHasErrors('note');

    expect($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval)
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

it('aprueba un pedido y avisa a la planta, no a quien aprobó', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->admin)
        ->post(route('sales-orders.approve', $order), ['note' => 'Cliente al día'])
        ->assertRedirect(route('sales-orders.show', $order))
        ->assertInertiaFlash('success', 'Pedido aprobado.');

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::Pending)
        ->and($order->approved_by)->toBe($this->admin->id)
        ->and($order->approved_at)->not->toBeNull()
        ->and($order->approval_note)->toBe('Cliente al día')
        ->and($order->is_partial_approval)->toBeFalse()
        // Producción y SuperAdmin crean OP; Admin también, pero fue quien aprobó.
        ->and(notifiedUserIds(SalesOrderApproved::class))->toEqualCanonicalizing([$this->production->id, $this->superAdmin->id])
        ->and(notifiedUserIds(SalesOrderReleased::class))->toBe([]);

    expect($this->production->notifications()->sole()->data['message'])
        ->toBe("Pedido #{$order->id} de Pinturas Andinas aprobado");
});

it('aprueba como parcial con la nota en el aviso', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), ['is_partial' => true, 'note' => 'Solo el primer despacho'])
        ->assertInertiaFlash('success', 'Pedido aprobado como parcial.');

    expect($order->fresh()->is_partial_approval)->toBeTrue()
        ->and(notifiedUserIds(SalesOrderApproved::class))->toEqualCanonicalizing([$this->admin->id, $this->production->id, $this->superAdmin->id])
        ->and($this->production->notifications()->sole()->data['message'])
        ->toBe("Pedido #{$order->id} de Pinturas Andinas aprobado parcial: Solo el primer despacho");
});

// Retener

it('exige el motivo para retener', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.hold', $order), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval);
});

it('retiene un pedido y avisa solo a quien lo creó', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.hold', $order), ['reason' => 'Cartera vencida'])
        ->assertRedirect(route('sales-orders.show', $order))
        ->assertInertiaFlash('success', 'Pedido retenido.');

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::OnHold)
        ->and($order->held_by)->toBe($this->coordinator->id)
        ->and($order->held_at)->not->toBeNull()
        ->and($order->hold_reason)->toBe('Cartera vencida')
        ->and(DatabaseNotification::query()->count())->toBe(1)
        ->and($this->commercial->notifications()->sole()->data)->toBe([
            'sales_order_id' => $order->id,
            'kind' => 'on_hold',
            'message' => "Pedido #{$order->id} de Pinturas Andinas retenido: Cartera vencida",
        ]);
});

it('avisa de la retención al creador de la cotización, no a quien la convirtió', function () {
    $quotationAuthor = userWithRole(SystemRole::Commercial);
    $quotation = Quotation::factory()->create(['created_by' => $quotationAuthor->id, 'status' => QuotationStatus::Accepted]);
    // Como en convertToOrder: el pedido convertido figura a nombre del creador de la cotización.
    $order = orderFor($quotationAuthor, SalesOrderStatus::PendingApproval, ['quotation_id' => $quotation->id]);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.hold', $order), ['reason' => 'Cartera vencida'])
        ->assertRedirect();

    expect(notifiedUserIds(SalesOrderOnHold::class))->toBe([$quotationAuthor->id]);
});

it('no deja retener un pedido ya retenido ni uno pendiente', function (SalesOrderStatus $status) {
    $order = orderFor($this->commercial, $status);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.hold', $order), ['reason' => 'Otra vez'])
        ->assertForbidden();
})->with([SalesOrderStatus::OnHold, SalesOrderStatus::Pending]);

it('no deja retener sin el permiso', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->commercial)
        ->post(route('sales-orders.hold', $order), ['reason' => 'Cartera vencida'])
        ->assertForbidden();
});

// Aprobar un retenido

it('aprueba un retenido: conserva la retención, avisa a la planta y le dice al creador que se liberó', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::OnHold, [
        'held_by' => $this->coordinator->id,
        'held_at' => now()->subDay(),
        'hold_reason' => 'Cartera vencida',
    ]);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), ['is_partial' => true, 'note' => 'Abonó la mitad'])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::Pending)
        ->and($order->held_by)->toBe($this->coordinator->id)
        ->and($order->hold_reason)->toBe('Cartera vencida')
        ->and($order->approved_by)->toBe($this->coordinator->id)
        ->and(notifiedUserIds(SalesOrderApproved::class))->toEqualCanonicalizing([$this->admin->id, $this->production->id, $this->superAdmin->id])
        ->and(notifiedUserIds(SalesOrderReleased::class))->toBe([$this->commercial->id])
        ->and($this->commercial->notifications()->sole()->data['message'])
        ->toBe("Pedido #{$order->id} de Pinturas Andinas liberado parcial: Abonó la mitad");
});

it('deja cancelar un retenido a quien aprueba', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::OnHold);

    $this->actingAs($this->coordinator)
        ->patch(route('sales-orders.update-status', $order), ['status' => SalesOrderStatus::Cancelled->value])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled);
});

it('no deja a la planta cancelar un retenido: es decisión de cartera', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::OnHold);

    $this->actingAs($this->production)
        ->patch(route('sales-orders.update-status', $order), ['status' => SalesOrderStatus::Cancelled->value])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::OnHold);
});

it('no deja pasar de retenido a pendiente sin aprobarlo', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::OnHold);

    $this->actingAs($this->coordinator)
        ->patch(route('sales-orders.update-status', $order), ['status' => SalesOrderStatus::Pending->value])
        ->assertSessionHasErrors('status');

    expect($order->fresh()->status)->toBe(SalesOrderStatus::OnHold);
});

// Un pedido por aprobar solo sale aprobándolo o reteniéndolo.

it('no deja cambiar el estado de un pedido por aprobar, ni con el permiso de cambiar estado', function (SalesOrderStatus $target) {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);

    $this->actingAs($this->admin)
        ->patch(route('sales-orders.update-status', $order), ['status' => $target->value])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval);
})->with([SalesOrderStatus::Pending, SalesOrderStatus::Cancelled]);

// Concurrencia

it('rechaza retener un pedido que otra petición aprobó mientras tanto, sin aviso', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);
    // La policy de la petición lo vio «Por aprobar»; antes del bloqueo, otra petición lo aprobó.
    $stale = SalesOrder::query()->findOrFail($order->id);
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => SalesOrderStatus::Pending->value]);

    expect(fn () => app(HoldSalesOrderAction::class)->execute($stale, $this->coordinator, 'Cartera vencida'))
        ->toThrow(ValidationException::class, 'El pedido ya cambió de estado.');

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Pending)
        ->and($order->fresh()->hold_reason)->toBeNull()
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

// Editar los datos del pedido

it('deja editar los datos mientras el pedido no arranca', function (SalesOrderStatus $status) {
    $order = orderFor($this->commercial, $status);

    $this->actingAs($this->coordinator)
        ->patch(route('sales-orders.update', $order), ['notes' => 'Entregar en portería'])
        ->assertRedirect();

    expect($order->fresh()->notes)->toBe('Entregar en portería');
})->with([SalesOrderStatus::PendingApproval, SalesOrderStatus::OnHold, SalesOrderStatus::Pending]);

it('no deja editar los datos de un pedido en progreso', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::InProgress);

    $this->actingAs($this->admin)
        ->patch(route('sales-orders.update', $order), ['notes' => 'Tarde'])
        ->assertForbidden();
});

// Pantallas

it('muestra los botones y la ficha de la decisión en el detalle', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::OnHold, [
        'held_by' => $this->coordinator->id,
        'held_at' => now(),
        'hold_reason' => 'Cartera vencida',
    ]);

    $this->actingAs($this->coordinator)
        ->get(route('sales-orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.approve', true)
            ->where('can.hold', false)
            ->where('order.approval.held_by', $this->coordinator->name)
            ->where('order.approval.hold_reason', 'Cartera vencida')
            ->where('order.approval.approved_at', null)
            ->where('order.approval.is_partial', false));

    $this->actingAs($this->commercial)
        ->get(route('sales-orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('can.approve', false)->where('can.hold', false));
});

it('lleva la etiqueta de parcial al listado', function () {
    orderFor($this->commercial, SalesOrderStatus::Pending, ['is_partial_approval' => true, 'approved_at' => now()]);

    $this->actingAs($this->coordinator)
        ->get(route('sales-orders.index'))
        ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.is_partial_approval', true));
});

it('cuenta los pedidos por aprobar en el menú y en el dashboard de quien aprueba', function () {
    orderFor($this->commercial, SalesOrderStatus::PendingApproval);
    orderFor($this->commercial, SalesOrderStatus::PendingApproval);
    // Los retenidos esperan al cliente: no cuentan.
    orderFor($this->commercial, SalesOrderStatus::OnHold);

    $this->actingAs($this->coordinator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingApprovalCount', 2)
            ->where('profile', 'commercial')
            ->where('stats.pending_approval_orders', 2));

    $this->actingAs($this->commercial)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingApprovalCount', 0)
            ->missing('stats.pending_approval_orders'));
});

// Avisos de la campana

it('entrega a cada usuario solo sus avisos sin leer', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);
    $this->actingAs($this->coordinator)->post(route('sales-orders.hold', $order), ['reason' => 'Cartera vencida']);

    $this->actingAs($this->commercial)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread_count', 1)
            ->has('notifications.items', 1, fn (Assert $item) => $item
                ->where('sales_order_id', $order->id)
                ->where('message', "Pedido #{$order->id} de Pinturas Andinas retenido: Cartera vencida")
                ->etc()));

    // La campana se muestra a todos: también a quien no tiene alertas ni avisos.
    $this->actingAs($this->production)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread_count', 0)
            ->where('notifications.items', []));
});

it('marca leído un aviso propio y abre el pedido', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);
    $this->actingAs($this->coordinator)->post(route('sales-orders.hold', $order), ['reason' => 'Cartera vencida']);
    $notification = $this->commercial->notifications()->sole();

    $this->actingAs($this->commercial)
        ->post(route('notifications.read', $notification->id))
        ->assertRedirect(route('sales-orders.show', $order));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('responde 404 al marcar leído un aviso ajeno', function () {
    $order = orderFor($this->commercial, SalesOrderStatus::PendingApproval);
    $this->actingAs($this->coordinator)->post(route('sales-orders.hold', $order), ['reason' => 'Cartera vencida']);
    $notification = $this->commercial->notifications()->sole();

    $this->actingAs($this->production)
        ->post(route('notifications.read', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('responde 404, no 500, a un id de aviso que no es un uuid', function () {
    // En PostgreSQL la columna es `uuid` y rechaza otro formato antes de buscar: la ruta lo filtra.
    $this->actingAs($this->commercial)
        ->post('/notifications/no-es-un-uuid/read')
        ->assertNotFound();
});

it('marca leídos todos los avisos propios y ninguno ajeno', function () {
    $this->actingAs($this->commercial)
        ->post(route('sales-orders.store'), approvalOrderPayload(Client::factory()->create(), approvalTestVariant()));

    expect($this->coordinator->unreadNotifications()->count())->toBe(1)
        ->and($this->superAdmin->unreadNotifications()->count())->toBe(1);

    $this->actingAs($this->coordinator)->post(route('notifications.read-all'))->assertRedirect();

    expect($this->coordinator->unreadNotifications()->count())->toBe(0)
        ->and($this->superAdmin->unreadNotifications()->count())->toBe(1);
});

// Rol «Coordinación comercial» y el permiso que solo elige destinatarios

it('deja a Admin asignar el rol de coordinación comercial y gestionar a ese usuario', function () {
    // Desactivar es la casilla «Activo» del formulario de edición (borrar es de SuperAdmin).
    expect(app(AssignableRoleService::class)->namesFor($this->admin))->toContain(SystemRole::CommercialCoordination->value)
        ->and($this->admin->can('update', $this->coordinator))->toBeTrue();

    $this->actingAs($this->admin)
        ->put(route('users.update', $this->coordinator), [
            'name' => $this->coordinator->name,
            'email' => $this->coordinator->email,
            'phone' => '3001112222',
            'role' => SystemRole::CommercialCoordination->value,
            'is_active' => false,
        ])
        ->assertRedirect(route('users.index'));

    expect($this->coordinator->fresh()->is_active)->toBeFalse();
});

it('no le avisa a una coordinadora inactiva', function () {
    $this->coordinator->update(['is_active' => false]);

    $this->actingAs($this->commercial)
        ->post(route('sales-orders.store'), approvalOrderPayload(Client::factory()->create(), approvalTestVariant()));

    expect(notifiedUserIds(SalesOrderAwaitingApproval::class))->toBe([$this->superAdmin->id]);
});

it('deja sin hallazgos la auditoría de roles con el rol nuevo', function () {
    $this->artisan('roles:audit')
        ->expectsOutputToContain('Todos los roles personalizados cumplen las reglas')
        ->assertSuccessful();
});
