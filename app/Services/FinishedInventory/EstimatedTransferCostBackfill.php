<?php

declare(strict_types=1);

namespace App\Services\FinishedInventory;

use App\Enums\FinishedInventoryMovementReason;
use App\Enums\InventoryMovementType;
use App\Enums\NumberType;
use App\Services\DecimalCalculator;
use App\Services\Pricing\ProductionCostCalculatorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relleno del costo de traslado de los lotes de PT creados antes de que el lote lo guardara. Lo llama la migración
 * 2026_11_17_110001; está aquí, y no en ella, para poder probarlo.
 *
 * Por cada lote sin costo de traslado: el `cost_price` de su entrada «Producción» (la primera, si hubiera más) con el CIF
 * que el producto tiene **hoy** (el del día de completar no quedó guardado en ningún lado), marcado como estimado. Un
 * lote sin entrada de producción, o cuya entrada no tiene costo, queda nulo.
 *
 * Escribe con el constructor de consultas, sin modelos: no deja registros de auditoría por cada lote y no depende de
 * cómo evolucione el modelo. Solo toca lotes con el costo nulo, así que correrlo dos veces no cambia nada.
 */
class EstimatedTransferCostBackfill
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductionCostCalculatorService $productionCostCalculator,
    ) {}

    /** Devuelve cuántos lotes quedaron con costo estimado. */
    public function run(): int
    {
        $filled = 0;

        DB::table('finished_product_batches')
            ->whereNull('transfer_unit_cost')
            ->select(['id', 'product_id'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $batches) use (&$filled): void {
                $entryCosts = $this->productionEntryCosts($batches->pluck('id')->all());
                $cifByProduct = DB::table('products')
                    ->whereIn('id', $batches->pluck('product_id')->unique()->all())
                    ->pluck('cif_percentage', 'id');

                foreach ($batches as $batch) {
                    $entryCost = $entryCosts->get((int) $batch->id);

                    if ($entryCost === null) {
                        continue;
                    }

                    $cif = $cifByProduct->get((int) $batch->product_id);
                    $transferCost = $this->calculator->round(
                        $this->productionCostCalculator->transferCost((string) $entryCost, $cif !== null ? (string) $cif : null),
                        NumberType::Money->scale(),
                    );

                    // Un costo que no cabe en la columna haría fallar la migración en PostgreSQL: se deja nulo, como el
                    // lote sin entrada.
                    if ($this->calculator->cmp($this->calculator->abs($transferCost), NumberType::Money->max()) > 0) {
                        continue;
                    }

                    DB::table('finished_product_batches')->where('id', $batch->id)->update([
                        'transfer_unit_cost' => $transferCost,
                        'transfer_cost_estimated' => true,
                    ]);
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * El `cost_price` de la primera entrada de producción con costo de cada lote, por id de lote.
     *
     * @param  list<int>  $batchIds
     * @return Collection<int, string>
     */
    private function productionEntryCosts(array $batchIds): Collection
    {
        return DB::table('finished_inventory_movements')
            ->whereIn('finished_product_batch_id', $batchIds)
            ->where('type', InventoryMovementType::Entry->value)
            ->where('reason', FinishedInventoryMovementReason::Production->value)
            ->whereNotNull('cost_price')
            ->orderBy('id')
            ->get(['finished_product_batch_id', 'cost_price'])
            ->unique('finished_product_batch_id')
            ->mapWithKeys(fn (object $movement): array => [(int) $movement->finished_product_batch_id => (string) $movement->cost_price]);
    }
}
