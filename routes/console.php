<?php

declare(strict_types=1);

use App\Enums\RecalculationKind;
use App\Jobs\RecalculateRawMaterialDependentCosts;
use App\Jobs\RecalculateRawMaterialReferencePrice;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Services\AlertService;
use App\Services\ProductionCostRecalculationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

$plantTimezone = (string) config('app.plant_timezone', 'America/Bogota');

Schedule::command('activitylog:clean')->dailyAt('03:00')->timezone($plantTimezone);
Schedule::command('alerts:check-expiry')->dailyAt('06:00')->timezone($plantTimezone);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'costs:recalculate-product {product_id? : ID de producto a recalcular} {--chunk=100 : Tamaño de lote para recálculo masivo}',
    function (): int {
        $service = app(ProductionCostRecalculationService::class);
        $productId = $this->argument('product_id');

        if ($productId !== null) {
            $product = Product::query()->select('id', 'name')->find((int) $productId);

            if ($product === null) {
                $this->error("No existe producto con ID {$productId}.");

                return Command::FAILURE;
            }

            try {
                $result = $service->recalculateForProduct((int) $product->id);
            } catch (DomainException $exception) {
                $this->error("Producto {$product->name}: no se pudo recalcular. {$exception->getMessage()}");

                return Command::FAILURE;
            }

            if ($result === null) {
                $this->warn("Producto {$product->name}: sin fórmula de referencia de precio, no se recalculó.");

                return Command::SUCCESS;
            }

            $this->info("Producto {$product->name}: recálculo completado.");

            return Command::SUCCESS;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $total = 0;
        $recalculated = 0;

        // Un producto que no se puede costear (una línea sin peso, un producto fuente sin costo) queda como estaba y en
        // el log, como en los recálculos de la cola: no detiene a los demás.
        Product::query()
            ->select('id')
            ->whereHas('priceReferenceFormula')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($products) use ($service, &$total, &$recalculated): void {
                foreach ($products as $product) {
                    $total++;
                    if ($service->recalculateForProductOrLog((int) $product->id) !== null) {
                        $recalculated++;
                    }
                }
            });

        $this->info("Productos con fórmula de referencia procesados: {$total}");
        $this->info("Productos recalculados: {$recalculated}");

        if ($recalculated < $total) {
            $this->warn('Productos sin recalcular (se dejaron como estaban): '.($total - $recalculated).'. El motivo de cada uno está en el log.');
        }

        // Recalcular todos los productos cubre lo que hacían los recálculos de unidad y de cadena de productos que fallaron.
        $resolved = app(AlertService::class)->resolveRecalculationFailures(
            RecalculationKind::UnitCosts,
            RecalculationKind::ProductChain,
        );

        if ($resolved > 0) {
            $this->info("Alertas de fallo de recálculo resueltas: {$resolved}");
        }

        return Command::SUCCESS;
    }
)->purpose('Recalcula costos y precios de productos/variantes para backfill');

// Repite por la cola los recálculos de una materia prima: su precio de referencia (si controla inventario) y los costos
// que dependen de ella, aunque el precio no haya cambiado. Es lo que indica la alerta «Fallo de recálculo». Sin ID,
// recalcula el precio de referencia de todas las activas que controlan inventario (y los costos de las que cambien).
Artisan::command(
    'costs:recalculate-raw-material {raw_material_id? : ID de la materia prima; sin él, el precio de todas las activas}',
    function (): int {
        $rawMaterialId = $this->argument('raw_material_id');

        if ($rawMaterialId !== null) {
            $rawMaterial = RawMaterial::query()->select('id', 'code', 'tracks_inventory')->find((int) $rawMaterialId);

            if ($rawMaterial === null) {
                $this->error("No existe materia prima con ID {$rawMaterialId}.");

                return Command::FAILURE;
            }

            // Primero el precio y después los costos: con un solo trabajador van en orden, y si el precio cambia, el
            // recálculo de costos que encola se une a este (mismo trabajo único) y corre una sola vez con el precio nuevo.
            if ($rawMaterial->tracks_inventory) {
                RecalculateRawMaterialReferencePrice::dispatch((int) $rawMaterial->id);
            }

            RecalculateRawMaterialDependentCosts::dispatch((int) $rawMaterial->id);
            $this->info("Materia prima {$rawMaterial->code}: recálculo enviado a la cola.");

            return Command::SUCCESS;
        }

        $total = 0;

        RawMaterial::query()
            ->select('id')
            ->where('is_active', true)
            ->where('tracks_inventory', true)
            ->chunkById(500, function ($rawMaterials) use (&$total): void {
                foreach ($rawMaterials as $rawMaterial) {
                    RecalculateRawMaterialReferencePrice::dispatch((int) $rawMaterial->id);
                    $total++;
                }
            });

        $this->info("Materias primas enviadas a recalcular su precio de referencia: {$total}");
        $this->line('Para recalcular el costo de todos los productos: php artisan costs:recalculate-product');

        return Command::SUCCESS;
    }
)->purpose('Recalcula en la cola el precio de referencia y los costos de una materia prima, o el precio de todas las activas');
