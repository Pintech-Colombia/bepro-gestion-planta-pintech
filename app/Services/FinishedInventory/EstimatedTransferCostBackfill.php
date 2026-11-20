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
                    $cif = $cifByProduct->get((int) $batch->product_id);
                    $transferCost = $this->estimatedCost($entryCosts->get((int) $batch->id), $cif !== null ? (string) $cif : null);

                    if ($transferCost === null) {
                        continue;
                    }

                    $this->store((int) $batch->id, $transferCost);
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * El costo de traslado estimado de un lote que no lo tiene, con la misma regla del relleno, sin guardarlo: la vista
     * previa de costos de una OP que lo usa. Nulo si el lote ya tiene costo de traslado o no se puede estimar.
     */
    public function estimateFor(int $batchId): ?string
    {
        $batch = DB::table('finished_product_batches')
            ->where('id', $batchId)
            ->whereNull('transfer_unit_cost')
            ->first(['id', 'product_id']);

        if ($batch === null) {
            return null;
        }

        $cif = DB::table('products')->where('id', $batch->product_id)->value('cif_percentage');

        return $this->estimatedCost(
            $this->productionEntryCosts([(int) $batch->id])->get((int) $batch->id),
            $cif !== null ? (string) $cif : null,
        );
    }

    /**
     * Estima y guarda, marcado como estimado, el costo de traslado de un lote que no lo tiene (uno creado por una imagen
     * anterior a la columna, durante una vuelta atrás de un despliegue). Lo llama completar una OP que lo consume, con el
     * lote ya bloqueado. Nulo si no se puede estimar.
     */
    public function fill(int $batchId): ?string
    {
        $transferCost = $this->estimateFor($batchId);

        if ($transferCost !== null) {
            $this->store($batchId, $transferCost);
        }

        return $transferCost;
    }

    /**
     * La regla: el costo de la entrada «Producción» con el CIF de hoy del producto. Nulo sin entrada, o si el costo no
     * cabe en la columna (haría fallar la migración en PostgreSQL: se deja nulo, como el lote sin entrada).
     */
    private function estimatedCost(?string $entryCost, ?string $cif): ?string
    {
        if ($entryCost === null) {
            return null;
        }

        $transferCost = $this->calculator->round(
            $this->productionCostCalculator->transferCost($entryCost, $cif),
            NumberType::Money->scale(),
        );

        return $this->calculator->cmp($this->calculator->abs($transferCost), NumberType::Money->max()) > 0
            ? null
            : $transferCost;
    }

    private function store(int $batchId, string $transferCost): void
    {
        DB::table('finished_product_batches')->where('id', $batchId)->update([
            'transfer_unit_cost' => $transferCost,
            'transfer_cost_estimated' => true,
        ]);
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
