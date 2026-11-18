<?php

declare(strict_types=1);

use App\Actions\Production\CorrectRemnantAction;
use App\Actions\Production\WriteOffRemnantAction;
use App\Enums\Permission;
use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantMovementType;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\RemnantConsumption;
use App\Services\Production\RemnantDrawdown;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/*
 * Saldos manuales: dar de baja y corregir kilos, con nota, permiso propio y su historial. Ninguno deja el saldo por
 * debajo de lo planeado en órdenes abiertas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = userWithRole(SystemRole::Admin);
    $this->sourceOrder = ProductionOrder::factory()->create(['created_by' => $this->admin->id]);
    // 50 kg a 5 kg/gal = 10 gal, a $5,5 el galón.
    $this->remnant = ProductionRemnant::factory()->create([
        'source_order_id' => $this->sourceOrder->id,
        'product_id' => $this->sourceOrder->product_id,
        'warehouse_id' => $this->sourceOrder->warehouse_id,
        'cost_per_gallon' => '5.5',
        'created_by' => $this->admin->id,
    ]);
});

/** Una orden abierta de la bodega del saldo con kilos planeados de él (sin aplicar). */
function adjustmentPlannedLine(object $test, string $kilos, ProductionOrderStatus $status = ProductionOrderStatus::InProgress): RemnantConsumption
{
    $order = ProductionOrder::factory()->create([
        'status' => $status,
        'warehouse_id' => $test->remnant->warehouse_id,
        'created_by' => $test->admin->id,
    ]);

    return RemnantConsumption::create([
        'remnant_id' => $test->remnant->id,
        'target_order_id' => $order->id,
        'quantity_kg' => $kilos,
        'quantity_gallons' => app(RemnantDrawdown::class)->gallonsFor($test->remnant, $kilos),
        'consumed_by' => $test->admin->id,
        'consumed_at' => now(),
    ]);
}

// --- Baja ---

it('da de baja un saldo: queda en 0 kg y consumido, con la nota en su historial', function () {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.write-off', $this->remnant), ['notes' => 'Se secó en el tanque abierto'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Saldo dado de baja.');

    $this->remnant->refresh();
    $movement = $this->remnant->movements()->sole();

    expect($this->remnant->available_quantity_kg)->toBe('0.0000')
        ->and($this->remnant->available_quantity_gallons)->toBe('0.0000')
        ->and($this->remnant->status)->toBe(RemnantStatus::Consumed)
        // El costo por galón no se toca.
        ->and($this->remnant->cost_per_gallon)->toBe('5.5000')
        ->and($movement->type)->toBe(RemnantMovementType::WriteOff)
        ->and($movement->quantity_kg)->toBe('50.0000')
        ->and($movement->decreasesBalance())->toBeTrue()
        ->and($movement->balance_after_kg)->toBe('0.0000')
        ->and($movement->notes)->toBe('Se secó en el tanque abierto')
        ->and($movement->user_id)->toBe($this->admin->id)
        ->and($movement->production_order_id)->toBeNull();

    $activity = Activity::query()->where('subject_type', ProductionRemnantMovement::class)->sole();

    expect($activity->log_name)->toBe('saldos_produccion')
        ->and($activity->event)->toBe('created')
        ->and($activity->causer_id)->toBe($this->admin->id);
});

it('pide una nota de 10 a 1.000 caracteres para dar de baja', function (?string $notes) {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.write-off', $this->remnant), array_filter(['notes' => $notes]))
        ->assertSessionHasErrors('notes');

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000')
        ->and(ProductionRemnantMovement::count())->toBe(0);
})->with([
    'sin nota' => [null],
    'muy corta' => ['Se secó'],
    'muy larga' => [str_repeat('a', 1001)],
]);

it('no da de baja un saldo que una orden abierta tiene planeado, y nombra la orden', function () {
    $line = adjustmentPlannedLine($this, '12');

    $this->actingAs($this->admin)
        ->post(route('production.remnants.write-off', $this->remnant), ['notes' => 'Se dañó con agua'])
        ->assertSessionHasErrors([
            WriteOffRemnantAction::ERROR_KEY => 'No se puede dar de baja: lo tienen planeado órdenes abiertas ('.$line->targetOrder->order_number.' (12 kg)). Quítalo primero de esas órdenes.',
        ]);

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000')
        ->and(ProductionRemnantMovement::count())->toBe(0);
});

it('lo planeado en una orden cancelada o completada no impide la baja', function (ProductionOrderStatus $status) {
    adjustmentPlannedLine($this, '12', $status);

    $this->actingAs($this->admin)
        ->post(route('production.remnants.write-off', $this->remnant), ['notes' => 'Se dañó con agua'])
        ->assertSessionHasNoErrors();

    expect($this->remnant->fresh()->status)->toBe(RemnantStatus::Consumed);
})->with([ProductionOrderStatus::Cancelled, ProductionOrderStatus::Completed]);

it('no da de baja un saldo agotado: responde con el aviso, no con 403', function () {
    $this->remnant->update(['available_quantity_kg' => 0, 'available_quantity_gallons' => 0, 'status' => RemnantStatus::Consumed]);

    $this->actingAs($this->admin)
        ->post(route('production.remnants.write-off', $this->remnant), ['notes' => 'Se dañó con agua'])
        ->assertSessionHasErrors(['remnant' => 'Este saldo ya está agotado: no hay nada que dar de baja.']);

    expect($this->remnant->movements()->count())->toBe(0);
});

it('si el saldo se agotó mientras se escribía la nota, responde con el aviso y no con un error', function () {
    // La política vio el saldo con kilos; antes del bloqueo, otra orden lo agotó.
    $stale = ProductionRemnant::query()->findOrFail($this->remnant->id);
    DB::table('production_remnants')->where('id', $this->remnant->id)
        ->update(['available_quantity_kg' => 0, 'available_quantity_gallons' => 0, 'status' => RemnantStatus::Consumed->value]);

    expect(fn () => app(WriteOffRemnantAction::class)->execute($stale, 'Se dañó con agua', $this->admin))
        ->toThrow(ValidationException::class, 'Este saldo ya está agotado: no hay nada que dar de baja.');

    expect(ProductionRemnantMovement::count())->toBe(0);
});

// --- Corrección ---

it('corrige hacia abajo: guarda la diferencia con signo, recalcula los galones y no cambia el costo', function () {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), [
            'quantity_kg' => '32.5',
            'notes' => 'Se pesó de nuevo en la báscula',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('success', 'Kilos del saldo corregidos.');

    $this->remnant->refresh();
    $movement = $this->remnant->movements()->sole();

    expect($this->remnant->available_quantity_kg)->toBe('32.5000')
        ->and($this->remnant->available_quantity_gallons)->toBe('6.5000')
        ->and($this->remnant->cost_per_gallon)->toBe('5.5000')
        // Pesar de nuevo no es consumir: ninguna orden lo usó.
        ->and($this->remnant->status)->toBe(RemnantStatus::Available)
        // Lo que nació no cambia: es el dato del día de la OP.
        ->and($this->remnant->original_quantity_kg)->toBe('50.0000')
        ->and($movement->type)->toBe(RemnantMovementType::Correction)
        ->and($movement->quantity_kg)->toBe('-17.5000')
        ->and($movement->decreasesBalance())->toBeTrue()
        ->and($movement->balance_after_kg)->toBe('32.5000')
        ->and($movement->notes)->toBe('Se pesó de nuevo en la báscula');
});

it('corrige hacia arriba un saldo que nunca se usó y sigue disponible', function () {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), [
            'quantity_kg' => '55',
            'notes' => 'Quedaba más en el fondo del tanque',
        ])
        ->assertSessionHasNoErrors();

    $this->remnant->refresh();

    expect($this->remnant->available_quantity_kg)->toBe('55.0000')
        ->and($this->remnant->available_quantity_gallons)->toBe('11.0000')
        ->and($this->remnant->status)->toBe(RemnantStatus::Available)
        ->and($this->remnant->movements()->sole()->quantity_kg)->toBe('5.0000');
});

it('un saldo consumido y corregido hacia arriba vuelve a parcialmente consumido', function () {
    // Se usó entero en una orden: tiene un consumo aplicado.
    $line = adjustmentPlannedLine($this, '50', ProductionOrderStatus::Completed);
    $line->update(['applied_at' => now()]);
    $this->remnant->update(['available_quantity_kg' => 0, 'available_quantity_gallons' => 0, 'status' => RemnantStatus::Consumed]);

    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), [
            'quantity_kg' => '3',
            'notes' => 'Quedó producto en la caneca',
        ])
        ->assertSessionHasNoErrors();

    $this->remnant->refresh();

    expect($this->remnant->available_quantity_kg)->toBe('3.0000')
        ->and($this->remnant->available_quantity_gallons)->toBe('0.6000')
        ->and($this->remnant->status)->toBe(RemnantStatus::PartiallyConsumed)
        ->and($this->remnant->movements()->sole()->quantity_kg)->toBe('3.0000');
});

it('no corrige a 0 kg, ni con kilos inválidos, ni sin nota', function (array $payload, string $field) {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), $payload)
        ->assertSessionHasErrors($field);

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('50.0000')
        ->and(ProductionRemnantMovement::count())->toBe(0);
})->with([
    'a cero' => [['quantity_kg' => '0', 'notes' => 'Se pesó de nuevo'], 'quantity_kg'],
    'negativo' => [['quantity_kg' => '-5', 'notes' => 'Se pesó de nuevo'], 'quantity_kg'],
    'cinco decimales' => [['quantity_kg' => '10.12345', 'notes' => 'Se pesó de nuevo'], 'quantity_kg'],
    'más de lo que cabe' => [['quantity_kg' => '100000000', 'notes' => 'Se pesó de nuevo'], 'quantity_kg'],
    'texto' => [['quantity_kg' => 'diez', 'notes' => 'Se pesó de nuevo'], 'quantity_kg'],
    'sin nota' => [['quantity_kg' => '40'], 'notes'],
]);

it('no corrige a los mismos kilos ni a tan pocos que no alcanzan 0,0001 gal', function (string $kilos, string $message) {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), ['quantity_kg' => $kilos, 'notes' => 'Se pesó de nuevo'])
        ->assertSessionHasErrors([CorrectRemnantAction::ERROR_KEY => $message]);

    expect(ProductionRemnantMovement::count())->toBe(0);
})->with([
    'los mismos' => ['50', 'El saldo ya tiene esos kilos: no hay nada que corregir.'],
    // 0,0002 kg ÷ 5 kg/gal = 0,00004 gal.
    'casi nada' => ['0.0002', 'Son tan pocos kilos que el saldo no se podría usar. Si ya no queda nada, dalo de baja.'],
]);

it('no corrige por debajo de lo planeado en órdenes abiertas, y nombra las órdenes', function () {
    $first = adjustmentPlannedLine($this, '20');
    $second = adjustmentPlannedLine($this, '5.5', ProductionOrderStatus::Pending);

    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), ['quantity_kg' => '25', 'notes' => 'Se pesó de nuevo'])
        ->assertSessionHasErrors([
            CorrectRemnantAction::ERROR_KEY => 'No puede quedar con menos de 25,5 kg: lo tienen planeado órdenes abiertas ('
                .$first->targetOrder->order_number.' (20 kg), '.$second->targetOrder->order_number.' (5,5 kg)).',
        ]);

    // Justo lo planeado sí se puede.
    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), ['quantity_kg' => '25.5', 'notes' => 'Se pesó de nuevo'])
        ->assertSessionHasNoErrors();

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('25.5000');
});

// --- Concurrencia: el saldo se bloquea ---

it('una corrección hecha con un saldo viejo parte de los kilos que quedaron tras completar la otra orden', function () {
    $line = adjustmentPlannedLine($this, '30');
    // Quien corrige abrió el diálogo con 50 kg; mientras tanto la otra orden se completó y descontó sus 30 kg.
    $stale = ProductionRemnant::query()->findOrFail($this->remnant->id);
    DB::transaction(function () use ($line): void {
        $locked = ProductionRemnant::query()->lockForUpdate()->findOrFail($line->remnant_id);
        app(RemnantDrawdown::class)->apply($line, $locked, $this->admin->id);
    });

    app(CorrectRemnantAction::class)->execute($stale, '25', 'Se pesó de nuevo', $this->admin);

    $movements = $this->remnant->movements()->orderBy('id')->get();

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('25.0000')
        ->and($movements->pluck('type')->all())->toBe([RemnantMovementType::Consumption, RemnantMovementType::Correction])
        // La diferencia contra los 20 kg que quedaban, no contra los 50 que vio.
        ->and($movements->last()->quantity_kg)->toBe('5.0000')
        ->and($movements->pluck('balance_after_kg')->all())->toBe(['20.0000', '25.0000']);
});

it('si la corrección llega primero, la orden que se completa después descuenta de los kilos corregidos sin dejarlo negativo', function () {
    $line = adjustmentPlannedLine($this, '30');

    app(CorrectRemnantAction::class)->execute($this->remnant, '35', 'Se pesó de nuevo', $this->admin);

    DB::transaction(function () use ($line): void {
        $locked = ProductionRemnant::query()->lockForUpdate()->findOrFail($line->remnant_id);
        app(RemnantDrawdown::class)->apply($line, $locked, $this->admin->id);
    });

    expect($this->remnant->fresh()->available_quantity_kg)->toBe('5.0000')
        ->and($this->remnant->movements()->orderByDesc('id')->value('balance_after_kg'))->toBe('5.0000');
});

it('bloquea la fila del saldo al dar de baja y al corregir', function (string $action) {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $movement = $action === 'baja'
        ? app(WriteOffRemnantAction::class)->execute($this->remnant, 'Se dañó con agua', $this->admin)
        : app(CorrectRemnantAction::class)->execute($this->remnant, '40', 'Se pesó de nuevo', $this->admin);

    expect($movement->exists)->toBeTrue()
        ->and(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "production_remnants"') && str_contains($sql, 'for update')))->toBeTrue();
})->with(['baja', 'corrección'])
    ->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'SQLite no tiene bloqueo de filas: solo se mide en PostgreSQL.');

// --- Permisos ---

it('Admin y Producción ajustan saldos; el operario no', function (SystemRole $role, bool $allowed) {
    $user = userWithRole($role);

    expect($user->can(Permission::ProductionRemnantsAdjust->value))->toBe($allowed);

    $response = $this->actingAs($user)
        ->post(route('production.remnants.correct', $this->remnant), ['quantity_kg' => '40', 'notes' => 'Se pesó de nuevo']);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'admin' => [SystemRole::Admin, true],
    'producción' => [SystemRole::Production, true],
    // Decisión del 2026-11-18: el operario maneja los saldos en planta.
    'operador' => [SystemRole::Operator, true],
]);

it('el operario también da de baja, y queda a su nombre en el historial', function () {
    $operator = userWithRole(SystemRole::Operator);

    $this->actingAs($operator)
        ->post(route('production.remnants.write-off', $this->remnant), ['notes' => 'Se dañó con agua'])
        ->assertSessionHasNoErrors();

    expect($this->remnant->fresh()->status)->toBe(RemnantStatus::Consumed)
        ->and($this->remnant->movements()->sole()->user_id)->toBe($operator->id);
});

it('el permiso no es reservado y exige ver saldos', function () {
    expect(Permission::ProductionRemnantsAdjust->isReserved())->toBeFalse()
        ->and(Permission::ProductionRemnantsAdjust->isRecipientOnly())->toBeFalse()
        ->and(Permission::ProductionRemnantsAdjust->requires())->toBe([Permission::ProductionRemnantsView]);
});

it('roles:audit sigue limpio con el permiso nuevo', function () {
    test()->seed(RolePermissionSeeder::class);

    expect(Artisan::call('roles:audit'))->toBe(0);
});

// --- Pantallas ---

it('el listado envía lo planeado por orden y si se puede ajustar', function (SystemRole $role, bool $canAdjust) {
    $line = adjustmentPlannedLine($this, '12');

    $this->actingAs(userWithRole($role))
        ->get(route('production.remnants.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Production/Remnants/Index')
            ->where('can.adjust', $canAdjust)
            ->where('remnants.data.0.committed_quantity_kg', 12)
            ->where('remnants.data.0.planned_orders', [[
                'order_id' => $line->target_order_id,
                'order_number' => $line->targetOrder->order_number,
                'quantity_kg' => '12.0000',
            ]]));
})->with([
    'producción' => [SystemRole::Production, true],
    'operador' => [SystemRole::Operator, true],
]);

it('el detalle muestra el historial, del más nuevo al más viejo, y el costo solo con permiso de costos', function (SystemRole $role, bool $seesCost) {
    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), ['quantity_kg' => '45', 'notes' => 'Se pesó de nuevo'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('production.remnants.write-off', $this->remnant), ['notes' => 'Se dañó con agua'])
        ->assertSessionHasNoErrors();

    $this->actingAs(userWithRole($role))
        ->get(route('production.remnants.show', $this->remnant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Production/Remnants/Show')
            ->where('remnant.cost_per_gallon', $seesCost ? '5.5000' : null)
            ->where('remnant.status', RemnantStatus::Consumed->value)
            ->where('movements.0.type', RemnantMovementType::WriteOff->value)
            ->where('movements.0.direction', 'out')
            ->where('movements.0.quantity_kg', '45.0000')
            ->where('movements.0.balance_after_kg', '0.0000')
            ->where('movements.0.user_name', $this->admin->name)
            ->where('movements.1.type', RemnantMovementType::Correction->value)
            ->where('movements.1.direction', 'out')
            ->where('movements.1.quantity_kg', '5.0000')
            ->where('movements.1.notes', 'Se pesó de nuevo')
            ->where('can.viewCosts', $seesCost));
})->with([
    'admin' => [SystemRole::Admin, true],
    'operador' => [SystemRole::Operator, false],
]);

it('el detalle pide permiso de ver saldos', function () {
    $this->actingAs(userWithPermissions([Permission::DashboardView]))
        ->get(route('production.remnants.show', $this->remnant))
        ->assertForbidden();
});

it('cada corrección queda una sola vez en la auditoría: en el movimiento, no también en el saldo', function () {
    Activity::query()->delete();

    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), [
            'quantity_kg' => '32.5',
            'notes' => 'Se pesó de nuevo en la báscula',
        ])
        ->assertSessionHasNoErrors();

    $subjects = Activity::query()->pluck('subject_type')->all();

    expect($subjects)->toBe([ProductionRemnantMovement::class]);
});

it('no corrige un saldo de una bodega inactiva: le devolvería kilos a una bodega que ya no los recibe', function () {
    $this->remnant->update(['available_quantity_kg' => 0, 'available_quantity_gallons' => 0, 'status' => RemnantStatus::Consumed]);
    $this->remnant->warehouse()->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->post(route('production.remnants.correct', $this->remnant), [
            'quantity_kg' => '10',
            'notes' => 'Quedaba algo en el fondo de la caneca',
        ])
        ->assertSessionHasErrors(['quantity_kg' => 'La bodega del saldo está inactiva: reactívala antes de corregir sus kilos.']);

    expect($this->remnant->fresh()->status)->toBe(RemnantStatus::Consumed)
        ->and($this->remnant->movements()->count())->toBe(0);
});
