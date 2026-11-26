<?php

declare(strict_types=1);

use App\Jobs\RecalculateRawMaterialReferencePrice;
use App\Models\Product;
use App\Models\RawMaterial;
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

            $result = $service->recalculateForProduct((int) $product->id);

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

        Product::query()
            ->select('id')
            ->whereHas('priceReferenceFormula')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($products) use ($service, &$total, &$recalculated): void {
                foreach ($products as $product) {
                    $total++;
                    if ($service->recalculateForProduct((int) $product->id) !== null) {
                        $recalculated++;
                    }
                }
            });

        $this->info("Productos con fórmula de referencia procesados: {$total}");
        $this->info("Productos recalculados: {$recalculated}");

        return Command::SUCCESS;
    }
)->purpose('Recalcula costos y precios de productos/variantes para backfill');

// Recalcula el precio de referencia (y, si cambia, los costos que dependen de él) por la cola, como lo hace un movimiento
// de inventario. Sirve cuando un recálculo falló (la alerta «Fallo de recálculo» dice cuál) o para recalcularlas todas.
Artisan::command(
    'costs:recalculate-raw-material {raw_material_id? : ID de la materia prima; sin él, todas las activas}',
    function (): int {
        $rawMaterialId = $this->argument('raw_material_id');

        if ($rawMaterialId !== null) {
            $rawMaterial = RawMaterial::query()->select('id', 'code')->find((int) $rawMaterialId);

            if ($rawMaterial === null) {
                $this->error("No existe materia prima con ID {$rawMaterialId}.");

                return Command::FAILURE;
            }

            RecalculateRawMaterialReferencePrice::dispatch((int) $rawMaterial->id);
            $this->info("Materia prima {$rawMaterial->code}: recálculo enviado a la cola.");

            return Command::SUCCESS;
        }

        $total = 0;

        RawMaterial::query()
            ->select('id')
            ->where('is_active', true)
            ->chunkById(500, function ($rawMaterials) use (&$total): void {
                foreach ($rawMaterials as $rawMaterial) {
                    RecalculateRawMaterialReferencePrice::dispatch((int) $rawMaterial->id);
                    $total++;
                }
            });

        $this->info("Materias primas activas enviadas a recalcular: {$total}");

        return Command::SUCCESS;
    }
)->purpose('Recalcula en la cola el precio de referencia de una materia prima o de todas las activas');
