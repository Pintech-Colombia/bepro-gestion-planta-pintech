<?php

declare(strict_types=1);

use App\Actions\SalesOrders\ApproveSalesOrderAction;
use App\Actions\SalesOrders\SplitSalesOrderRemainder;
use App\Enums\SalesOrderPriority;
use App\Enums\SalesOrderStatus;
use App\Enums\SystemRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Notifications\SalesOrders\SalesOrderApproved;
use App\Notifications\SalesOrders\SalesOrderOnHold;
use App\Notifications\SalesOrders\SalesOrderReleased;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Aprobación parcial por productos: al aprobar parcial se indica cuánto sale ahora de cada línea. Lo aprobado se queda
 * en el pedido («Pendiente»); lo demás pasa a un pedido nuevo «Retenido», enlazado al original, con la nota como motivo.
 */
beforeEach(function (): void {
    $this->coordinator = userWithRole(SystemRole::CommercialCoordination);
    $this->admin = userWithRole(SystemRole::Admin);
    $this->production = userWithRole(SystemRole::Production);
    $this->commercial = userWithRole(SystemRole::Commercial);
    $this->superAdmin = userWithRole(SystemRole::SuperAdmin);
});

/**
 * Pedido con una línea por cantidad, cada una de un producto distinto.
 *
 * @param  list<int>  $quantities
 */
function splitOrder(User $creator, array $quantities, SalesOrderStatus $status = SalesOrderStatus::PendingApproval, array $attributes = []): SalesOrder
{
    $client = Client::factory()->create(['business_name' => 'Pinturas Andinas']);
    $order = SalesOrder::factory()->create([
        'client_id' => $client->id,
        'client_business_name' => 'Pinturas Andinas',
        'client_nit' => '900123456',
        'client_contact_name' => 'Laura Gómez',
        'client_phone' => '3001234567',
        'shipping_address' => 'Calle 1 # 2-3, Cali',
        'notes' => 'Entregar en portería',
        'priority' => SalesOrderPriority::High->value,
        'required_date' => now()->addDays(10)->format('Y-m-d'),
        'estimated_delivery_date' => null,
        'created_by' => $creator->id,
        'status' => $status->value,
        ...$attributes,
    ]);

    foreach ($quantities as $index => $quantity) {
        SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => Product::factory()->create(['name' => "Producto {$order->id}-".($index + 1)])->id,
            'product_variant_id' => null,
            'quantity' => $quantity,
            'color' => $index === 0 ? 'RAL 3020' : null,
        ]);
    }

    return $order;
}

/**
 * Cuerpo de la petición: cantidades aprobadas en el orden de las líneas del pedido.
 *
 * @param  list<int|string>  $approved
 * @return array<string, mixed>
 */
function splitPayload(SalesOrder $order, array $approved, string $note = 'Falta cupo; abonar $2 M'): array
{
    $items = $order->items()->orderBy('id')->get()->values()
        ->map(fn (SalesOrderItem $item, int $index): array => ['id' => $item->id, 'approved_quantity' => $approved[$index]])
        ->all();

    return ['is_partial' => true, 'note' => $note, 'items' => $items];
}

/**
 * @return list<int>
 */
function splitNotifiedIds(string $type): array
{
    return DatabaseNotification::query()->where('type', $type)->orderBy('notifiable_id')
        ->pluck('notifiable_id')->map(fn ($id): int => (int) $id)->all();
}

/**
 * Unidades por producto entre todos los pedidos.
 *
 * @return array<int, int>
 */
function unitsByProduct(): array
{
    return SalesOrderItem::query()->get()->groupBy('product_id')
        ->map(fn ($items): int => (int) $items->sum('quantity'))->sortKeys()->all();
}

// Partir el pedido

it('aprueba parcial por producto: lo aprobado se queda y el resto pasa a un pedido nuevo retenido', function () {
    $order = splitOrder($this->commercial, [10, 20, 30, 40]);
    [$first, $second, $third, $fourth] = $order->items()->orderBy('id')->get()->all();

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), splitPayload($order, [10, 20, 0, 0]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('sales-orders.show', $order))
        ->assertInertiaFlash('success', 'Pedido aprobado como parcial.');

    $order->refresh();
    $remainder = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();

    expect($order->status)->toBe(SalesOrderStatus::Pending)
        ->and($order->is_partial_approval)->toBeTrue()
        ->and($order->approved_by)->toBe($this->coordinator->id)
        ->and($order->approval_note)->toBe('Falta cupo; abonar $2 M')
        ->and($order->items()->orderBy('id')->pluck('id')->all())->toBe([$first->id, $second->id])
        // El resto: mismo cliente, datos y creador; retenido por quien aprobó, con la nota como motivo.
        ->and($remainder->status)->toBe(SalesOrderStatus::OnHold)
        ->and($remainder->client_id)->toBe($order->client_id)
        ->and($remainder->created_by)->toBe($this->commercial->id)
        ->and($remainder->held_by)->toBe($this->coordinator->id)
        ->and($remainder->held_at)->not->toBeNull()
        ->and($remainder->hold_reason)->toBe('Falta cupo; abonar $2 M')
        ->and($remainder->approved_at)->toBeNull()
        ->and($remainder->is_partial_approval)->toBeFalse()
        ->and($remainder->priority)->toBe(SalesOrderPriority::High)
        ->and($remainder->required_date?->format('Y-m-d'))->toBe($order->required_date?->format('Y-m-d'))
        ->and($remainder->notes)->toBe('Entregar en portería')
        ->and($remainder->shipping_address)->toBe('Calle 1 # 2-3, Cali')
        ->and($remainder->client_business_name)->toBe('Pinturas Andinas')
        ->and($remainder->client_nit)->toBe('900123456')
        ->and($remainder->client_contact_name)->toBe('Laura Gómez')
        ->and($remainder->client_phone)->toBe('3001234567')
        ->and($remainder->quotation_id)->toBeNull()
        // Las líneas en 0 se mueven (las mismas filas), no se copian.
        ->and($remainder->items()->orderBy('id')->pluck('id')->all())->toBe([$third->id, $fourth->id])
        ->and(SalesOrderItem::query()->count())->toBe(4);

    // Aviso de aprobado a la planta (no a quien aprobó) y de retenido al creador.
    expect(splitNotifiedIds(SalesOrderApproved::class))
        ->toEqualCanonicalizing([$this->admin->id, $this->production->id, $this->superAdmin->id])
        ->and(splitNotifiedIds(SalesOrderOnHold::class))->toBe([$this->commercial->id])
        ->and(splitNotifiedIds(SalesOrderReleased::class))->toBe([])
        ->and($this->production->notifications()->sole()->data['message'])
        ->toBe("Pedido #{$order->id} de Pinturas Andinas aprobado parcial (resto en el #{$remainder->id}): Falta cupo; abonar \$2 M")
        ->and($this->commercial->notifications()->sole()->data)->toBe([
            'sales_order_id' => $remainder->id,
            'kind' => 'on_hold',
            'message' => "Pedido #{$remainder->id} de Pinturas Andinas (resto del #{$order->id}) retenido: Falta cupo; abonar \$2 M",
        ]);
});

it('aprueba parcial por cantidad: la línea se reduce y la diferencia se crea en el resto', function () {
    $order = splitOrder($this->commercial, [100]);
    $line = $order->items()->sole();

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), splitPayload($order, [40]))
        ->assertSessionHasNoErrors();

    $remainder = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();
    $remainderLine = $remainder->items()->sole();

    expect($line->fresh()->quantity)->toBe(40)
        ->and($line->fresh()->sales_order_id)->toBe($order->id)
        ->and($remainderLine->id)->not->toBe($line->id)
        ->and($remainderLine->quantity)->toBe(60)
        ->and($remainderLine->product_id)->toBe($line->product_id)
        ->and($remainderLine->product_variant_id)->toBe($line->product_variant_id)
        ->and($remainderLine->color)->toBe('RAL 3020');
});

it('conserva lo pedido de cada producto entre el original y el resto', function () {
    $order = splitOrder($this->commercial, [100, 7, 25, 3]);
    $before = unitsByProduct();

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), splitPayload($order, [40, 7, 0, '2']))
        ->assertSessionHasNoErrors();

    $remainder = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();

    expect(unitsByProduct())->toBe($before)
        ->and($order->items()->orderBy('id')->pluck('quantity')->all())->toBe([40, 7, 2])
        ->and($remainder->items()->orderBy('id')->pluck('quantity')->all())->toBe([25, 60, 1]);
});

it('no parte el pedido si la aprobación no es parcial, aunque lleguen cantidades', function () {
    $order = splitOrder($this->commercial, [10, 20]);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), [...splitPayload($order, [0, 5]), 'is_partial' => false])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Pending)
        ->and($order->fresh()->is_partial_approval)->toBeFalse()
        ->and($order->items()->orderBy('id')->pluck('quantity')->all())->toBe([10, 20])
        ->and(SalesOrder::query()->count())->toBe(1);
});

// Errores: nada cambia y no hay avisos

it('rechaza una aprobación parcial inválida sin tocar el pedido', function (Closure $payload, string $errorKey, string $message) {
    $order = splitOrder($this->commercial, [10, 20]);

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), $payload($order))
        ->assertSessionHasErrors([$errorKey => $message]);

    expect($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval)
        ->and($order->items()->orderBy('id')->pluck('quantity')->all())->toBe([10, 20])
        ->and(SalesOrder::query()->count())->toBe(1)
        ->and(DatabaseNotification::query()->count())->toBe(0);
})->with([
    'aprueba todo' => [fn (SalesOrder $order) => splitPayload($order, [10, 20]), 'items', SplitSalesOrderRemainder::NOTHING_LEFT],
    'no aprueba nada' => [fn (SalesOrder $order) => splitPayload($order, [0, 0]), 'items', SplitSalesOrderRemainder::NOTHING_APPROVED],
    'más de lo pedido' => [fn (SalesOrder $order) => splitPayload($order, [11, 0]), 'items.0.approved_quantity', 'No puedes aprobar más de lo pedido (10).'],
    'negativo' => [fn (SalesOrder $order) => splitPayload($order, [-1, 20]), 'items.0.approved_quantity', 'La cantidad no puede ser negativa.'],
    'decimal' => [fn (SalesOrder $order) => splitPayload($order, ['2.5', 0]), 'items.0.approved_quantity', 'El campo aprobar ahora debe ser un número entero.'],
    'falta una línea' => [fn (SalesOrder $order) => [...splitPayload($order, [5, 0]), 'items' => array_slice(splitPayload($order, [5, 0])['items'], 0, 1)], 'items', SplitSalesOrderRemainder::LINES_MISMATCH],
    'línea repetida' => [fn (SalesOrder $order) => [...splitPayload($order, [5, 0]), 'items' => [
        ['id' => $order->items()->orderBy('id')->value('id'), 'approved_quantity' => 5],
        ['id' => $order->items()->orderBy('id')->value('id'), 'approved_quantity' => 0],
    ]], 'items.0.id', 'Ese producto está repetido.'],
    'sin cantidades' => [fn (SalesOrder $order) => ['is_partial' => true, 'note' => 'Falta cupo'], 'items', SplitSalesOrderRemainder::LINES_MISMATCH],
]);

it('rechaza una línea de otro pedido', function () {
    $order = splitOrder($this->commercial, [10, 20]);
    $other = splitOrder($this->commercial, [5]);
    $payload = splitPayload($order, [5, 0]);
    $payload['items'][1]['id'] = $other->items()->value('id');

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $order), $payload)
        ->assertSessionHasErrors(['items.1.id' => 'Ese producto no es de este pedido.']);

    expect($other->items()->sole()->sales_order_id)->toBe($other->id)
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval);
});

it('vuelve a validar las líneas en la acción, con el pedido bloqueado', function () {
    $order = splitOrder($this->commercial, [10, 20]);
    $lineIds = $order->items()->orderBy('id')->pluck('id')->all();

    expect(fn () => app(ApproveSalesOrderAction::class)->execute($order, $this->coordinator, true, 'Falta cupo', [$lineIds[0] => 10]))
        ->toThrow(ValidationException::class, SplitSalesOrderRemainder::LINES_MISMATCH);
    expect(fn () => app(ApproveSalesOrderAction::class)->execute($order, $this->coordinator, true, 'Falta cupo', [$lineIds[0] => 11, $lineIds[1] => 0]))
        ->toThrow(ValidationException::class, SplitSalesOrderRemainder::LINES_MISMATCH);

    expect($order->fresh()->status)->toBe(SalesOrderStatus::PendingApproval)
        ->and(SalesOrder::query()->count())->toBe(1)
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

// Aprobar el resto

it('aprueba el resto completo: avisa a la planta y le dice al creador que se liberó', function () {
    $order = splitOrder($this->commercial, [10, 20]);
    $this->actingAs($this->coordinator)->post(route('sales-orders.approve', $order), splitPayload($order, [10, 0]));
    $remainder = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();
    DatabaseNotification::query()->delete();

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $remainder), ['note' => 'Abonó'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Pedido aprobado.');

    $remainder->refresh();
    expect($remainder->status)->toBe(SalesOrderStatus::Pending)
        ->and($remainder->hold_reason)->toBe('Falta cupo; abonar $2 M')
        ->and($remainder->items()->pluck('quantity')->all())->toBe([20])
        ->and($this->production->notifications()->sole()->data['message'])
        ->toBe("Pedido #{$remainder->id} de Pinturas Andinas (resto del #{$order->id}) aprobado")
        ->and($this->commercial->notifications()->sole()->data['message'])
        ->toBe("Pedido #{$remainder->id} de Pinturas Andinas (resto del #{$order->id}) liberado");
});

it('aprueba el resto parcial otra vez: el tercero cuelga del segundo', function () {
    $order = splitOrder($this->commercial, [10, 20, 30]);
    $this->actingAs($this->coordinator)->post(route('sales-orders.approve', $order), splitPayload($order, [10, 0, 0]));
    $second = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();

    $this->actingAs($this->coordinator)
        ->post(route('sales-orders.approve', $second), splitPayload($second, [20, 10], 'Abonó una parte'))
        ->assertSessionHasNoErrors();

    $third = SalesOrder::query()->where('parent_sales_order_id', $second->id)->sole();

    expect($second->fresh()->status)->toBe(SalesOrderStatus::Pending)
        ->and($second->items()->orderBy('id')->pluck('quantity')->all())->toBe([20, 10])
        ->and($third->status)->toBe(SalesOrderStatus::OnHold)
        ->and($third->hold_reason)->toBe('Abonó una parte')
        ->and($third->created_by)->toBe($this->commercial->id)
        ->and($third->items()->pluck('quantity')->all())->toBe([20])
        ->and($order->remainders()->pluck('id')->all())->toBe([$second->id])
        ->and($this->commercial->notifications()->get()->filter(fn ($notification): bool => $notification->data['kind'] === 'released')->sole()->data['message'])
        ->toBe("Pedido #{$second->id} de Pinturas Andinas (resto del #{$order->id}) liberado parcial (resto en el #{$third->id}): Abonó una parte");
});

// Concurrencia

it('deja ganar solo una de dos aprobaciones parciales del mismo pedido', function () {
    $order = splitOrder($this->commercial, [10, 20]);
    // Las dos peticiones leyeron el pedido «Por aprobar» antes de que la primera lo bloqueara.
    $stale = SalesOrder::query()->findOrFail($order->id);
    [$first, $second] = $order->items()->orderBy('id')->pluck('id')->all();
    $action = app(ApproveSalesOrderAction::class);

    $action->execute($order, $this->coordinator, true, 'Falta cupo', [$first => 10, $second => 0]);

    expect(fn () => $action->execute($stale, $this->admin, true, 'Otra nota', [$first => 5, $second => 20]))
        ->toThrow(ValidationException::class, 'El pedido ya cambió de estado.');

    expect(SalesOrder::query()->where('parent_sales_order_id', $order->id)->count())->toBe(1)
        ->and($order->fresh()->approval_note)->toBe('Falta cupo')
        ->and(SalesOrderItem::query()->orderBy('id')->pluck('quantity')->all())->toBe([10, 20])
        ->and(splitNotifiedIds(SalesOrderOnHold::class))->toBe([$this->commercial->id]);
});

// Auditoría y pantallas

it('deja en la auditoría la línea movida y la reducida', function () {
    $order = splitOrder($this->commercial, [10, 100]);
    [$moved, $reduced] = $order->items()->orderBy('id')->get()->all();

    $this->actingAs($this->coordinator)->post(route('sales-orders.approve', $order), splitPayload($order, [0, 40]));
    $remainder = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();

    $changeOf = fn (SalesOrderItem $item): Activity => Activity::query()
        ->where('subject_type', SalesOrderItem::class)
        ->where('subject_id', $item->id)
        ->where('event', 'updated')
        ->sole();

    expect($changeOf($moved)->properties['old'])->toBe(['sales_order_id' => $order->id])
        ->and($changeOf($moved)->properties['attributes'])->toBe(['sales_order_id' => $remainder->id])
        ->and($changeOf($moved)->causer_id)->toBe($this->coordinator->id)
        ->and($changeOf($reduced)->properties['old'])->toBe(['quantity' => 100])
        ->and($changeOf($reduced)->properties['attributes'])->toBe(['quantity' => 40])
        ->and($changeOf($reduced)->description)->toBe("Línea de pedido \"Pedido #{$order->id} · {$reduced->product->name}\" actualizada")
        // La diferencia de la línea reducida nace en el resto, y también queda.
        ->and(Activity::query()->where('subject_type', SalesOrderItem::class)->where('event', 'created')
            ->where('subject_id', $remainder->items()->whereKeyNot($moved->id)->sole()->id)->sole()->properties['attributes'])
        ->toBe(['sales_order_id' => $remainder->id, 'quantity' => 60]);
});

it('enlaza el original con su resto en el detalle', function () {
    $order = splitOrder($this->commercial, [10, 20]);
    $this->actingAs($this->coordinator)->post(route('sales-orders.approve', $order), splitPayload($order, [10, 0]));
    $remainder = SalesOrder::query()->where('parent_sales_order_id', $order->id)->sole();

    $this->actingAs($this->commercial)
        ->get(route('sales-orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.approval.remainder_ids', [$remainder->id])
            ->where('order.approval.parent_id', null)
            ->has('order.items', 1));

    $this->actingAs($this->commercial)
        ->get(route('sales-orders.show', $remainder))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status', SalesOrderStatus::OnHold->value)
            ->where('order.approval.parent_id', $order->id)
            ->where('order.approval.remainder_ids', [])
            ->where('order.approval.hold_reason', 'Falta cupo; abonar $2 M')
            ->has('order.items', 1));
});
