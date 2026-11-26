<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\Permission;
use App\Enums\RecalculationKind;
use App\Jobs\RecalculateProductDependentCosts;
use App\Jobs\RecalculateRawMaterialDependentCosts;
use App\Jobs\RecalculateRawMaterialReferencePrice;
use App\Jobs\RecalculateUnitDependentCosts;
use App\Models\Alert;
use App\Models\Formula;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\UnitOfMeasure;
use App\Services\AlertService;
use App\Services\ProductionCostRecalculationService;
use App\Services\RawMaterialReferencePriceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Un recálculo de costo que agota sus intentos deja una alerta en la campana (una por recálculo y elemento) y se resuelve
 * solo cuando el mismo recálculo del mismo elemento termina bien. La cola de pruebas es `sync`: un trabajo que lanza una
 * excepción falla en ese mismo intento y llama a su `failed()`, como en el último intento del servidor.
 */

/** Despacha el trabajo y se traga la excepción que la cola `sync` vuelve a lanzar tras llamar a `failed()`. */
function dispatchFailing(object $job): void
{
    try {
        dispatch($job);
    } catch (RuntimeException) {
        return;
    }

    test()->fail('El trabajo debía fallar.');
}

function recalculationFailure(RecalculationKind $kind, int $id): ?Alert
{
    return Alert::query()
        ->where('type', AlertType::FalloRecalculo)
        ->where('source_key', $kind->sourceKey($id))
        ->first();
}

function activeRecalculationFailures(): int
{
    return Alert::query()->where('type', AlertType::FalloRecalculo)->where('is_resolved', false)->count();
}

function failReferencePrices(): void
{
    test()->mock(RawMaterialReferencePriceService::class)
        ->shouldReceive('syncRawMaterialCurrentPrice')
        ->andThrow(new RuntimeException('Conexión con la base perdida'));
}

function failCostRecalculations(string $method): void
{
    test()->mock(ProductionCostRecalculationService::class)
        ->shouldReceive($method)
        ->andThrow(new RuntimeException('Sin memoria'));
}

test('a reference price recalculation that fails leaves one alert, however many times it fails', function () {
    Log::spy();
    $rawMaterial = RawMaterial::factory()->create(['code' => 'S - 12']);
    failReferencePrices();

    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));
    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $alert = Alert::query()->where('type', AlertType::FalloRecalculo)->sole();

    expect($alert->source_key)->toBe(RecalculationKind::ReferencePrice->sourceKey((int) $rawMaterial->id))
        ->and($alert->raw_material_id)->toBe($rawMaterial->id)
        ->and($alert->is_resolved)->toBeFalse()
        ->and($alert->message)->toContain('precio de referencia de S - 12')
        ->and($alert->message)->toContain("costs:recalculate-raw-material {$rawMaterial->id}")
        // El detalle técnico va al log, no al mensaje de la alerta.
        ->and($alert->message)->not->toContain('Conexión con la base perdida');

    Log::shouldHaveReceived('error')->twice();
});

test('a later successful reference price recalculation of the same raw material resolves its alert', function () {
    $rawMaterial = RawMaterial::factory()->create();
    failReferencePrices();
    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $this->app->forgetInstance(RawMaterialReferencePriceService::class);
    dispatch(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $alert = recalculationFailure(RecalculationKind::ReferencePrice, (int) $rawMaterial->id);
    expect($alert->is_resolved)->toBeTrue()
        ->and($alert->resolved_at)->not->toBeNull();
});

test('a successful recalculation of another raw material leaves the alert active', function () {
    [$failing, $other] = RawMaterial::factory()->count(2)->create();
    failReferencePrices();
    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $failing->id));

    $this->app->forgetInstance(RawMaterialReferencePriceService::class);
    dispatch(new RecalculateRawMaterialReferencePrice((int) $other->id));

    expect(activeRecalculationFailures())->toBe(1);
});

test('the dependent costs alert survives a reference price recalculation and resolves with its own success', function () {
    // Una MP sin inventario (el agua): cada OP que la consume encola el recálculo del precio de referencia, que no hace
    // nada con ella. Eso no debe resolver la alerta de sus costos.
    $water = RawMaterial::factory()->withoutInventoryTracking()->create(['code' => 'AGUA']);
    failCostRecalculations('recalculateForRawMaterial');

    dispatchFailing(new RecalculateRawMaterialDependentCosts((int) $water->id));

    $alert = recalculationFailure(RecalculationKind::RawMaterialCosts, (int) $water->id);
    expect($alert->raw_material_id)->toBe($water->id)
        ->and($alert->message)->toContain('productos y presentaciones que usan AGUA')
        ->and($alert->message)->toContain("costs:recalculate-raw-material {$water->id}");

    dispatch(new RecalculateRawMaterialReferencePrice((int) $water->id));
    expect($alert->fresh()->is_resolved)->toBeFalse();

    $this->app->forgetInstance(ProductionCostRecalculationService::class);
    dispatch(new RecalculateRawMaterialDependentCosts((int) $water->id));
    expect($alert->fresh()->is_resolved)->toBeTrue();
});

test('a reference price that changes queues the dependent costs instead of recalculating them inline', function () {
    // Separados, un fallo en los costos no deja el precio guardado y los costos viejos para siempre: el recálculo de
    // costos se reintenta solo y no depende de que el precio cambie.
    Queue::fake();
    $rawMaterial = RawMaterial::factory()->create(['current_price' => 100]);
    InventoryBatch::factory()->create([
        'raw_material_id' => $rawMaterial->id,
        'unit_price' => 150,
        'entry_date' => now()->toDateString(),
    ]);

    (new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id))->handle(
        app(RawMaterialReferencePriceService::class),
        app(AlertService::class),
    );

    expect((float) $rawMaterial->fresh()->current_price)->toBe(150.0);
    Queue::assertPushed(
        RecalculateRawMaterialDependentCosts::class,
        fn (RecalculateRawMaterialDependentCosts $job): bool => $job->rawMaterialId === $rawMaterial->id,
    );
});

test('a unit recalculation that fails leaves an alert and resolves on a later success', function () {
    $unit = UnitOfMeasure::factory()->create(['name' => 'Galón']);
    failCostRecalculations('recalculateForUnit');

    dispatchFailing(new RecalculateUnitDependentCosts((int) $unit->id));

    $alert = recalculationFailure(RecalculationKind::UnitCosts, (int) $unit->id);
    expect($alert->raw_material_id)->toBeNull()
        ->and($alert->message)->toContain('usan la unidad Galón')
        ->and($alert->message)->toContain('costs:recalculate-product');

    $this->app->forgetInstance(ProductionCostRecalculationService::class);
    dispatch(new RecalculateUnitDependentCosts((int) $unit->id));

    expect($alert->fresh()->is_resolved)->toBeTrue();
});

test('a product chain alert is one per product even if the product is renamed between failures', function () {
    $product = Product::factory()->create(['name' => 'Blanco base']);
    failCostRecalculations('recalculateDependentsOf');

    dispatchFailing(new RecalculateProductDependentCosts((int) $product->id));
    $product->update(['name' => 'Blanco puro']);
    dispatchFailing(new RecalculateProductDependentCosts((int) $product->id));

    $alert = Alert::query()->where('type', AlertType::FalloRecalculo)->sole();
    expect($alert->source_key)->toBe(RecalculationKind::ProductChain->sourceKey((int) $product->id))
        ->and($alert->message)->toContain('llevan BLANCO PURO en su fórmula');

    $this->app->forgetInstance(ProductionCostRecalculationService::class);
    dispatch(new RecalculateProductDependentCosts((int) $product->id));

    expect($alert->fresh()->is_resolved)->toBeTrue();
});

test('recalculating every product resolves the unit and product chain alerts, not the raw material ones', function () {
    $alerts = app(AlertService::class);
    $alerts->raiseRecalculationFailure(RecalculationKind::UnitCosts->sourceKey(3), null, 'Unidad');
    $alerts->raiseRecalculationFailure(RecalculationKind::ProductChain->sourceKey(4), null, 'Producto');
    $rawMaterial = RawMaterial::factory()->create();
    $alerts->raiseRecalculationFailure(RecalculationKind::RawMaterialCosts->sourceKey((int) $rawMaterial->id), (int) $rawMaterial->id, 'MP');

    $this->artisan('costs:recalculate-product')->assertSuccessful();

    expect(recalculationFailure(RecalculationKind::UnitCosts, 3)->is_resolved)->toBeTrue()
        ->and(recalculationFailure(RecalculationKind::ProductChain, 4)->is_resolved)->toBeTrue()
        ->and(recalculationFailure(RecalculationKind::RawMaterialCosts, (int) $rawMaterial->id)->is_resolved)->toBeFalse();
});

test('recalculating every product goes on past a product that cannot be costed', function () {
    Formula::factory()->count(2)->create();
    $this->partialMock(ProductionCostRecalculationService::class)
        ->shouldReceive('recalculateForProduct')
        ->andThrow(new DomainException('La línea no está en una unidad de peso.'));

    $this->artisan('costs:recalculate-product')
        ->expectsOutputToContain('Productos con fórmula de referencia procesados: 2')
        ->expectsOutputToContain('Productos sin recalcular (se dejaron como estaban): 2')
        ->assertSuccessful();
});

test('a failure for a raw material deleted in the meantime still leaves the alert, without the link', function () {
    $job = new RecalculateRawMaterialDependentCosts(999999);

    $job->failed(new RuntimeException('Sin memoria'));

    $alert = recalculationFailure(RecalculationKind::RawMaterialCosts, 999999);
    expect($alert)->not->toBeNull()
        ->and($alert->raw_material_id)->toBeNull()
        ->and($alert->message)->toContain('#999999');
});

test('an alert raised outside a request is not kept in a session', function () {
    // En el worker de la cola no hay petición: guardarla para el aviso en pantalla solo acumularía memoria.
    app(AlertService::class)->raiseRecalculationFailure(RecalculationKind::UnitCosts->sourceKey(1), null, 'Unidad');

    expect(activeRecalculationFailures())->toBe(1)
        ->and(app('session.store')->has('new_alerts'))->toBeFalse();
});

test('only users with the costs permission see the recalculation failure alert', function () {
    $rawMaterial = RawMaterial::factory()->create();
    failReferencePrices();
    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $withoutCosts = userWithPermissions([Permission::AlertsView, Permission::RawMaterialsView]);
    $withCosts = userWithPermissions([Permission::AlertsView, Permission::CostsView]);

    expect(Alert::query()->visibleTo($withoutCosts)->where('type', AlertType::FalloRecalculo)->count())->toBe(0)
        ->and(Alert::query()->visibleTo($withCosts)->where('type', AlertType::FalloRecalculo)->count())->toBe(1);
});

test('the command queues the reference price and the dependent costs of a raw material that tracks inventory', function () {
    Queue::fake();
    $rawMaterial = RawMaterial::factory()->create();

    $this->artisan('costs:recalculate-raw-material', ['raw_material_id' => $rawMaterial->id])->assertSuccessful();

    Queue::assertPushed(RecalculateRawMaterialReferencePrice::class, 1);
    Queue::assertPushed(
        RecalculateRawMaterialDependentCosts::class,
        fn (RecalculateRawMaterialDependentCosts $job): bool => $job->rawMaterialId === $rawMaterial->id,
    );
});

test('the command only queues the dependent costs of a raw material without inventory', function () {
    Queue::fake();
    $water = RawMaterial::factory()->withoutInventoryTracking()->create();

    $this->artisan('costs:recalculate-raw-material', ['raw_material_id' => $water->id])->assertSuccessful();

    Queue::assertNotPushed(RecalculateRawMaterialReferencePrice::class);
    Queue::assertPushed(RecalculateRawMaterialDependentCosts::class, 1);
});

test('without an id the command queues the reference price of every active raw material that tracks inventory', function () {
    Queue::fake();
    $active = RawMaterial::factory()->count(2)->create(['is_active' => true]);
    RawMaterial::factory()->create(['is_active' => false]);
    RawMaterial::factory()->withoutInventoryTracking()->create(['is_active' => true]);

    $this->artisan('costs:recalculate-raw-material')->assertSuccessful();

    $queued = Queue::pushed(RecalculateRawMaterialReferencePrice::class)
        ->map(fn (RecalculateRawMaterialReferencePrice $job): int => $job->rawMaterialId)
        ->sort()
        ->values()
        ->all();

    expect($queued)->toBe($active->pluck('id')->sort()->values()->all());
    Queue::assertNotPushed(RecalculateRawMaterialDependentCosts::class);
});

test('the command fails for a raw material that does not exist', function () {
    Queue::fake();

    $this->artisan('costs:recalculate-raw-material', ['raw_material_id' => 999999])->assertFailed();

    Queue::assertNothingPushed();
});
