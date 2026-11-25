<?php

declare(strict_types=1);

use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderLineAdjustment;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductionRemnant;
use App\Models\ProductVariant;
use App\Models\QrCode;
use App\Models\RawMaterial;
use App\Models\RemnantConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->order = ProductionOrder::factory()->inProgress()->create([
        'order_number' => 'OP-2026-0045',
        'created_by' => $this->user->id,
    ]);
    $this->rawMaterial = RawMaterial::factory()->create(['code' => 'S - 12']);
});

/** Los registros de un modelo, sin los que dejaron la OP y sus catálogos al crearse. */
function logsOf(string $subjectType): Collection
{
    return Activity::query()->where('subject_type', $subjectType)->orderBy('id')->get();
}

test('la línea de MP de la OP solo registra cuando cambia la cantidad real, con la OP en la descripción', function (): void {
    $detail = ProductionOrderDetail::create([
        'production_order_id' => $this->order->id,
        'raw_material_id' => $this->rawMaterial->id,
        'step_order' => 1,
        'planned_quantity' => '10',
        'unit_cost' => '2',
        'total_cost' => '20',
    ]);

    expect(logsOf(ProductionOrderDetail::class))->toBeEmpty();

    // El costo lo pone el sistema: solo no deja registro.
    $detail->update(['unit_cost' => '3', 'total_cost' => '30']);
    expect(logsOf(ProductionOrderDetail::class))->toBeEmpty();

    $detail->update(['actual_quantity' => '9.5']);

    $log = logsOf(ProductionOrderDetail::class)->sole();
    expect($log->log_name)->toBe('ordenes_produccion')
        ->and($log->event)->toBe('updated')
        ->and($log->description)->toBe('Línea de MP de OP "OP-2026-0045 · S - 12" actualizada')
        ->and($log->properties['attributes'])->toBe(['actual_quantity' => '9.5000'])
        ->and($log->causer_id)->toBe($this->user->id);
});

test('el plan de envasado registra crear, editar y borrar, sin el envase que guarda el sistema', function (): void {
    $variant = ProductVariant::factory()->create(['product_id' => $this->order->product_id, 'presentation_label' => 'GALÓN']);

    $plan = ProductionOrderPackagingPlan::createForVariant((int) $this->order->id, (int) $variant->id, 12);
    $plan->update(['actual_units' => 11, 'package_raw_material_id' => $this->rawMaterial->id]);
    $plan->update(['package_raw_material_id' => null]);
    $plan->delete();

    $logs = logsOf(ProductionOrderPackagingPlan::class);

    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($logs[0]->description)->toBe('Plan de envasado de OP "OP-2026-0045 · GALÓN" creado')
        ->and($logs[0]->properties['attributes'])->toMatchArray(['product_variant_id' => $variant->id, 'planned_units' => 12])
        ->and($logs[1]->properties['attributes'])->toBe(['actual_units' => 11])
        ->and($logs[2]->description)->toBe('Plan de envasado de OP "OP-2026-0045 · GALÓN" eliminado');
});

test('el ajuste de línea registra al agregarse y al quitarse', function (): void {
    $adjustment = ProductionOrderLineAdjustment::create([
        'production_order_id' => $this->order->id,
        'raw_material_id' => $this->rawMaterial->id,
        'quantity' => '1.5',
        'reason' => 'Corrección de viscosidad',
        'created_by' => $this->user->id,
    ]);
    $adjustment->delete();

    $logs = logsOf(ProductionOrderLineAdjustment::class);

    expect($logs->pluck('event')->all())->toBe(['created', 'deleted'])
        ->and($logs[0]->description)->toBe('Ajuste de línea de OP "OP-2026-0045 · S - 12" creado')
        ->and($logs[0]->properties['attributes'])->toMatchArray([
            'raw_material_id' => $this->rawMaterial->id,
            'quantity' => '1.5000',
            'reason' => 'Corrección de viscosidad',
        ])
        ->and($logs[1]->properties['old'])->toHaveKey('quantity', '1.5000');
});

test('el saldo usado en una OP registra crear, los kilos que cambian y borrar; no lo que calcula el sistema', function (): void {
    $sourceOrder = ProductionOrder::factory()->create(['order_number' => 'OP-2026-0030', 'created_by' => $this->user->id]);
    $remnant = ProductionRemnant::factory()->create([
        'source_order_id' => $sourceOrder->id,
        'product_id' => $sourceOrder->product_id,
        'warehouse_id' => $sourceOrder->warehouse_id,
        'created_by' => $this->user->id,
    ]);

    $consumption = RemnantConsumption::create([
        'remnant_id' => $remnant->id,
        'target_order_id' => $this->order->id,
        'quantity_kg' => '5',
        'quantity_gallons' => '1',
        'consumed_by' => $this->user->id,
        'consumed_at' => now(),
    ]);
    $consumption->update(['quantity_gallons' => '1.2', 'consumed_cost' => '100', 'applied_at' => now()]);
    $consumption->update(['quantity_kg' => '4.8']);
    $consumption->delete();

    $logs = logsOf(RemnantConsumption::class);

    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($logs[0]->description)->toBe('Saldo usado en OP "OP-2026-0045 · saldo de OP-2026-0030" creado')
        ->and($logs[0]->properties['attributes'])->toMatchArray(['remnant_id' => $remnant->id, 'quantity_kg' => '5.0000'])
        ->and($logs[1]->properties['attributes'])->toBe(['quantity_kg' => '4.8000']);
});

test('el QR solo registra cuando se activa o desactiva', function (): void {
    $qrCode = QrCode::factory()->create(['production_order_id' => $this->order->id, 'created_by' => $this->user->id]);

    expect(logsOf(QrCode::class))->toBeEmpty();

    $qrCode->update(['url' => 'https://qr.example.test/otra']);
    $qrCode->update(['is_active' => false]);

    $log = logsOf(QrCode::class)->sole();
    expect($log->log_name)->toBe('codigos_qr')
        ->and($log->description)->toBe('Código QR "OP-2026-0045" actualizado')
        ->and($log->properties['attributes'])->toBe(['is_active' => false])
        ->and($log->properties['old'])->toBe(['is_active' => true]);
});

test('el identificador de la auditoría no deja relaciones a medias en el modelo', function (): void {
    $variant = ProductVariant::factory()->create(['product_id' => $this->order->product_id]);
    $plan = ProductionOrderPackagingPlan::createForVariant((int) $this->order->id, (int) $variant->id, 12);

    // Crear el plan arma su descripción; lo que lea el código después tiene que ser el registro entero.
    expect($plan->relationLoaded('productVariant'))->toBeFalse()
        ->and($plan->productVariant->code)->toBe($variant->code);
});
