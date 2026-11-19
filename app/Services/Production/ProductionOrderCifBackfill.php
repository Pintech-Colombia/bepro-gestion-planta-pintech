<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\FinishedInventoryMovementReason;
use App\Enums\InventoryMovementType;
use App\Enums\NumberType;
use App\Enums\ProductionOrderStatus;
use App\Services\DecimalCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relleno del CIF de las OP completadas antes de que la orden lo guardara. Lo llama la migración 2026_11_19_100001;
 * está aquí, y no en ella, para poder probarlo.
 *
 * El CIF del día en que se completó no quedó guardado en la orden, pero sí en lo que la orden fijó con él:
 * 1. Un lote de PT con costo de traslado real (no estimado): CIF = costo de traslado ÷ costo de su entrada «Producción»
 *    − 1. Entre varios, el de mayor costo, que pierde menos decimales al despejar.
 * 2. Su saldo: CIF = costo por galón del saldo ÷ costo de granel por galón de la orden − 1. Guardó el CIF real del día
 *    en que nació, así que va antes que un lote cuyo costo de traslado se estimó después.
 * 3. Un lote con costo de traslado estimado, con la misma cuenta del paso 1, marcado como estimado.
 * 4. Sin ninguno, el CIF que el producto tiene hoy, marcado como estimado.
 *
 * Escribe con el constructor de consultas, sin modelos: no deja registros de auditoría por cada orden y no depende de
 * cómo evolucione el modelo. Solo toca órdenes sin CIF ni marca, así que correrlo dos veces no cambia nada.
 */
class ProductionOrderCifBackfill
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /** Devuelve cuántas órdenes quedaron con CIF (o con la marca de estimado). */
    public function run(): int
    {
        $filled = 0;

        DB::table('production_orders')
            ->where('status', ProductionOrderStatus::Completed->value)
            ->whereNull('cif_percentage')
            ->where('cif_percentage_estimated', false)
            ->select(['id', 'product_id'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $orders) use (&$filled): void {
                $orderIds = $orders->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $fromBatches = $this->cifFromBatches($orderIds);
                $fromRemnants = $this->cifFromRemnants($orderIds);
                $productCif = DB::table('products')
                    ->whereIn('id', $orders->pluck('product_id')->unique()->all())
                    ->pluck('cif_percentage', 'id');

                foreach ($orders as $order) {
                    $orderId = (int) $order->id;
                    $fromBatch = $fromBatches->get($orderId);
                    $resolved = $fromBatch !== null && ! $fromBatch['estimated']
                        ? $fromBatch
                        : $fromRemnants->get($orderId) ?? $fromBatch;

                    if ($resolved === null) {
                        $cif = $productCif->get((int) $order->product_id);
                        $resolved = ['cif' => $cif !== null ? (string) $cif : null, 'estimated' => true];
                    }

                    DB::table('production_orders')->where('id', $orderId)->update([
                        'cif_percentage' => $resolved['cif'],
                        'cif_percentage_estimated' => $resolved['estimated'],
                    ]);
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * El CIF que fijó un lote de cada orden, por id de orden.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, array{cif: string, estimated: bool}>
     */
    private function cifFromBatches(array $orderIds): Collection
    {
        $rows = DB::table('finished_product_batches as batches')
            ->join('finished_inventory_movements as entries', 'entries.finished_product_batch_id', '=', 'batches.id')
            ->whereIn('batches.production_order_id', $orderIds)
            ->whereNotNull('batches.transfer_unit_cost')
            ->where('entries.type', InventoryMovementType::Entry->value)
            ->where('entries.reason', FinishedInventoryMovementReason::Production->value)
            ->whereNotNull('entries.cost_price')
            ->orderBy('batches.id')
            ->orderBy('entries.id')
            ->get([
                'batches.production_order_id',
                'batches.transfer_unit_cost',
                'batches.transfer_cost_estimated',
                'entries.cost_price',
            ]);

        $resolved = collect();

        foreach ($rows->groupBy('production_order_id') as $orderId => $orderRows) {
            // Real antes que estimado; entre iguales, el mayor costo (pierde menos decimales al despejar el CIF).
            $best = $orderRows
                ->filter(fn (object $row): bool => $this->calculator->isPositive((string) $row->cost_price))
                ->sort(function (object $a, object $b): int {
                    $estimated = (int) (bool) $a->transfer_cost_estimated <=> (int) (bool) $b->transfer_cost_estimated;

                    return $estimated !== 0
                        ? $estimated
                        : $this->calculator->cmp((string) $b->cost_price, (string) $a->cost_price);
                })
                ->first();

            if ($best === null) {
                continue;
            }

            $cif = $this->cifFromRatio((string) $best->transfer_unit_cost, (string) $best->cost_price);

            if ($cif !== null) {
                $resolved->put((int) $orderId, ['cif' => $cif, 'estimated' => (bool) $best->transfer_cost_estimated]);
            }
        }

        return $resolved;
    }

    /**
     * El CIF que fijó el saldo de cada orden, por id de orden: su costo por galón contra el granel por galón de la orden.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, array{cif: string, estimated: bool}>
     */
    private function cifFromRemnants(array $orderIds): Collection
    {
        $rows = DB::table('production_remnants as remnants')
            ->join('production_costs as costs', 'costs.production_order_id', '=', 'remnants.source_order_id')
            ->whereIn('remnants.source_order_id', $orderIds)
            ->whereNotNull('remnants.cost_per_gallon')
            ->whereNotNull('costs.unit_cost')
            ->get(['remnants.source_order_id', 'remnants.cost_per_gallon', 'costs.unit_cost']);

        $resolved = collect();

        foreach ($rows as $row) {
            if (! $this->calculator->isPositive((string) $row->unit_cost)) {
                continue;
            }

            $cif = $this->cifFromRatio((string) $row->cost_per_gallon, (string) $row->unit_cost);

            if ($cif !== null) {
                $resolved->put((int) $row->source_order_id, ['cif' => $cif, 'estimated' => false]);
            }
        }

        return $resolved;
    }

    /**
     * (con CIF ÷ sin CIF − 1) × 100, con los decimales del CIF. Null si sale negativo o no cabe en la columna: esa fuente
     * no sirve y se prueba la siguiente.
     */
    private function cifFromRatio(string $withCif, string $withoutCif): ?string
    {
        $ratio = $this->calculator->div($withCif, $withoutCif, 8);
        $cif = $this->calculator->round(
            $this->calculator->mul($this->calculator->sub($ratio, '1', 8), '100', 8),
            NumberType::Percentage->scale(),
        );

        if ($this->calculator->isNegative($cif, NumberType::Percentage->scale())
            || $this->calculator->cmp($cif, NumberType::Percentage->max(), NumberType::Percentage->scale()) > 0) {
            return null;
        }

        return $cif;
    }
}
