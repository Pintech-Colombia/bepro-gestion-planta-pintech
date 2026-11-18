<?php

declare(strict_types=1);

use App\Enums\ProductionOrderStatus;
use App\Enums\RemnantMovementType;
use App\Enums\RemnantStatus;
use App\Enums\SystemRole;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\RemnantConsumption;
use App\Models\User;
use App\Services\Production\RemnantMovementBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/*
 * Historial de los saldos que existían antes de la tabla de movimientos (los de staging): nacimiento y consumos
 * aplicados, en orden, y los kilos que quedan cuadran con el saldo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = userWithRole(SystemRole::Admin);
    $this->planner = User::factory()->create();
    $this->sourceOrder = ProductionOrder::factory()->create(['created_by' => $this->admin->id]);
    // Nació con 50 kg y se usaron 20 + 12,5 en dos órdenes completadas: quedan 17,5.
    $this->remnant = ProductionRemnant::factory()->create([
        'source_order_id' => $this->sourceOrder->id,
        'product_id' => $this->sourceOrder->product_id,
        'warehouse_id' => $this->sourceOrder->warehouse_id,
        'available_quantity_kg' => '17.5',
        'available_quantity_gallons' => '3.5',
        'status' => RemnantStatus::PartiallyConsumed,
        'created_by' => $this->admin->id,
        'created_at' => Carbon::parse('2026-10-01 14:00:00'),
    ]);
});

function backfillLine(object $test, string $kilos, ?string $appliedAt, ProductionOrderStatus $status = ProductionOrderStatus::Completed): RemnantConsumption
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
        'quantity_gallons' => '1',
        'consumed_by' => $test->planner->id,
        'consumed_at' => now(),
        'applied_at' => $appliedAt === null ? null : Carbon::parse($appliedAt),
    ]);
}

it('un saldo con dos consumos aplicados y uno planeado queda con 3 movimientos que cuadran', function () {
    // Se crean fuera de orden: el historial sigue la fecha en que se aplicaron.
    $second = backfillLine($this, '12.5', '2026-10-09 10:00:00');
    $first = backfillLine($this, '20', '2026-10-05 10:00:00');
    backfillLine($this, '4', null, ProductionOrderStatus::InProgress);

    expect(app(RemnantMovementBackfill::class)->run())->toBe(1);

    $movements = $this->remnant->movements()->orderBy('id')->get();

    expect($movements)->toHaveCount(3)
        ->and($movements->pluck('type')->all())->toBe([RemnantMovementType::Birth, RemnantMovementType::Consumption, RemnantMovementType::Consumption])
        ->and($movements->pluck('quantity_kg')->all())->toBe(['50.0000', '20.0000', '12.5000'])
        ->and($movements->pluck('balance_after_kg')->all())->toBe(['50.0000', '30.0000', '17.5000'])
        ->and($movements->last()->balance_after_kg)->toBe($this->remnant->fresh()->available_quantity_kg)
        ->and($movements->pluck('production_order_id')->all())->toBe([$this->sourceOrder->id, $first->target_order_id, $second->target_order_id])
        ->and($movements->pluck('remnant_consumption_id')->all())->toBe([null, $first->id, $second->id])
        // El nacimiento, de quien completó la OP de origen; cada consumo, de quien lo planeó (no se guardó quién completó).
        // Los consumos rellenados no tienen usuario: no se sabe quién completó la orden (no es quien planeó la línea).
        ->and($movements->pluck('user_id')->all())->toBe([$this->admin->id, null, null])
        ->and($movements->map(fn (ProductionRemnantMovement $movement) => $movement->created_at->toDateTimeString())->all())
        ->toBe(['2026-10-01 14:00:00', '2026-10-05 10:00:00', '2026-10-09 10:00:00'])
        ->and($movements->pluck('notes')->filter()->all())->toBe([]);
});

it('no deja registros de auditoría por cada movimiento rellenado', function () {
    backfillLine($this, '32.5', '2026-10-05 10:00:00');
    $before = Activity::query()->count();

    app(RemnantMovementBackfill::class)->run();

    expect(Activity::query()->count())->toBe($before);
});

it('si los kilos viejos no cuadran, cierra con una corrección sin usuario que lo dice', function () {
    // Se usaron 30 kg pero el saldo dice 17,5: faltan 2,5 kg sin explicación.
    backfillLine($this, '30', '2026-10-05 10:00:00');

    app(RemnantMovementBackfill::class)->run();

    $last = $this->remnant->movements()->orderByDesc('id')->firstOrFail();

    expect($this->remnant->movements()->count())->toBe(3)
        ->and($last->type)->toBe(RemnantMovementType::Correction)
        ->and($last->quantity_kg)->toBe('-2.5000')
        ->and($last->balance_after_kg)->toBe('17.5000')
        ->and($last->user_id)->toBeNull()
        ->and($last->notes)->toBe(RemnantMovementBackfill::MISMATCH_NOTE);
});

it('es idempotente y no toca los saldos que ya tienen historial', function () {
    backfillLine($this, '32.5', '2026-10-05 10:00:00');
    $other = ProductionRemnant::factory()->create([
        'source_order_id' => ProductionOrder::factory()->create(['created_by' => $this->admin->id])->id,
        'product_id' => $this->sourceOrder->product_id,
        'warehouse_id' => $this->sourceOrder->warehouse_id,
        'created_by' => $this->admin->id,
    ]);
    ProductionRemnantMovement::create([
        'production_remnant_id' => $other->id,
        'type' => RemnantMovementType::Birth,
        'quantity_kg' => '50',
        'balance_after_kg' => '50',
        'production_order_id' => $other->source_order_id,
        'user_id' => $this->admin->id,
    ]);

    expect(app(RemnantMovementBackfill::class)->run())->toBe(1)
        ->and(app(RemnantMovementBackfill::class)->run())->toBe(0)
        ->and($this->remnant->movements()->count())->toBe(2)
        ->and($other->movements()->count())->toBe(1);
});

it('un saldo sin consumos queda solo con su nacimiento', function () {
    $this->remnant->update(['available_quantity_kg' => 50, 'available_quantity_gallons' => 10, 'status' => RemnantStatus::Available]);

    app(RemnantMovementBackfill::class)->run();

    expect($this->remnant->movements()->sole()->balance_after_kg)->toBe('50.0000');
});

it('la migración llama al relleno', function () {
    backfillLine($this, '32.5', '2026-10-05 10:00:00');

    $migration = require database_path('migrations/2026_11_18_100001_backfill_production_remnant_movements.php');
    $migration->up();

    expect($this->remnant->movements()->count())->toBe(2);
});
