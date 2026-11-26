<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\Permission;
use App\Jobs\RecalculateProductDependentCosts;
use App\Jobs\RecalculateRawMaterialReferencePrice;
use App\Models\Alert;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Services\ProductionCostRecalculationService;
use App\Services\RawMaterialReferencePriceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Un recálculo de costo que agota sus intentos deja una alerta en la campana (una por elemento) y se resuelve solo
 * cuando un recálculo posterior del mismo elemento termina bien. La cola de pruebas es `sync`: un trabajo que lanza una
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

function activeRecalculationFailures(): int
{
    return Alert::query()->where('type', AlertType::FalloRecalculo)->where('is_resolved', false)->count();
}

test('a reference price recalculation that fails leaves one alert per raw material, however many times it fails', function () {
    Log::spy();
    $rawMaterial = RawMaterial::factory()->create(['code' => 'S - 12']);
    $this->mock(RawMaterialReferencePriceService::class)
        ->shouldReceive('syncRawMaterialCurrentPrice')
        ->andThrow(new RuntimeException('Conexión con la base perdida'));

    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));
    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $alert = Alert::query()->where('type', AlertType::FalloRecalculo)->sole();

    expect($alert->raw_material_id)->toBe($rawMaterial->id)
        ->and($alert->is_resolved)->toBeFalse()
        ->and($alert->message)->toContain('precio de referencia de S - 12')
        ->and($alert->message)->toContain("costs:recalculate-raw-material {$rawMaterial->id}")
        // El detalle técnico va al log, no al mensaje de la alerta.
        ->and($alert->message)->not->toContain('Conexión con la base perdida');

    Log::shouldHaveReceived('error')->twice();
});

test('a later successful recalculation of the same raw material resolves the alert', function () {
    $rawMaterial = RawMaterial::factory()->create();
    $this->mock(RawMaterialReferencePriceService::class)
        ->shouldReceive('syncRawMaterialCurrentPrice')
        ->andThrow(new RuntimeException('Sin memoria'));

    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));
    expect(activeRecalculationFailures())->toBe(1);

    $this->app->forgetInstance(RawMaterialReferencePriceService::class);
    dispatch(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $alert = Alert::query()->where('type', AlertType::FalloRecalculo)->sole();
    expect($alert->is_resolved)->toBeTrue()
        ->and($alert->resolved_at)->not->toBeNull();
});

test('a successful recalculation of another raw material leaves the alert active', function () {
    [$failing, $other] = RawMaterial::factory()->count(2)->create();
    $this->mock(RawMaterialReferencePriceService::class)
        ->shouldReceive('syncRawMaterialCurrentPrice')
        ->andThrow(new RuntimeException('Sin memoria'));

    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $failing->id));

    $this->app->forgetInstance(RawMaterialReferencePriceService::class);
    dispatch(new RecalculateRawMaterialReferencePrice((int) $other->id));

    expect(activeRecalculationFailures())->toBe(1);
});

test('a product chain recalculation that fails leaves one alert per product and resolves on a later success', function () {
    $product = Product::factory()->create(['name' => 'Blanco base']);
    $this->mock(ProductionCostRecalculationService::class)
        ->shouldReceive('recalculateDependentsOf')
        ->andThrow(new RuntimeException('Dato raro'));

    dispatchFailing(new RecalculateProductDependentCosts((int) $product->id));
    dispatchFailing(new RecalculateProductDependentCosts((int) $product->id));

    $alert = Alert::query()->where('type', AlertType::FalloRecalculo)->sole();
    expect($alert->raw_material_id)->toBeNull()
        ->and($alert->message)->toContain('llevan BLANCO BASE en su fórmula')
        ->and($alert->message)->toContain('costs:recalculate-product');

    $this->app->forgetInstance(ProductionCostRecalculationService::class);
    dispatch(new RecalculateProductDependentCosts((int) $product->id));

    expect(activeRecalculationFailures())->toBe(0);
});

test('only users with the costs permission see the recalculation failure alert', function () {
    $rawMaterial = RawMaterial::factory()->create();
    $this->mock(RawMaterialReferencePriceService::class)
        ->shouldReceive('syncRawMaterialCurrentPrice')
        ->andThrow(new RuntimeException('Sin memoria'));

    dispatchFailing(new RecalculateRawMaterialReferencePrice((int) $rawMaterial->id));

    $withoutCosts = userWithPermissions([Permission::AlertsView, Permission::RawMaterialsView]);
    $withCosts = userWithPermissions([Permission::AlertsView, Permission::CostsView]);

    expect(Alert::query()->visibleTo($withoutCosts)->where('type', AlertType::FalloRecalculo)->count())->toBe(0)
        ->and(Alert::query()->visibleTo($withCosts)->where('type', AlertType::FalloRecalculo)->count())->toBe(1);
});

test('the command queues the recalculation of one raw material', function () {
    Queue::fake();
    $rawMaterial = RawMaterial::factory()->create();

    $this->artisan('costs:recalculate-raw-material', ['raw_material_id' => $rawMaterial->id])
        ->assertSuccessful();

    Queue::assertPushed(RecalculateRawMaterialReferencePrice::class, 1);
    Queue::assertPushed(
        RecalculateRawMaterialReferencePrice::class,
        fn (RecalculateRawMaterialReferencePrice $job): bool => $job->rawMaterialId === $rawMaterial->id,
    );
});

test('the command queues every active raw material when no id is given', function () {
    Queue::fake();
    $active = RawMaterial::factory()->count(2)->create(['is_active' => true]);
    $inactive = RawMaterial::factory()->create(['is_active' => false]);

    $this->artisan('costs:recalculate-raw-material')->assertSuccessful();

    $queued = Queue::pushed(RecalculateRawMaterialReferencePrice::class)
        ->map(fn (RecalculateRawMaterialReferencePrice $job): int => $job->rawMaterialId)
        ->sort()
        ->values()
        ->all();

    expect($queued)->toBe($active->pluck('id')->sort()->values()->all())
        ->and($queued)->not->toContain($inactive->id);
});

test('the command fails for a raw material that does not exist', function () {
    Queue::fake();

    $this->artisan('costs:recalculate-raw-material', ['raw_material_id' => 999999])->assertFailed();

    Queue::assertNothingPushed();
});
