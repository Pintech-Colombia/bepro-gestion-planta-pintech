<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Models\FinishedProductBatch;
use App\Models\FinishedProductBatchStock;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Support\NumberFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cuántas unidades de cada lote de PT quedan **libres** en una bodega: su stock menos las unidades **comprometidas**,
 * las de las fuentes de lote sin aplicar en órdenes abiertas (pendientes, en curso o en revisión). El hermano de
 * `AvailableRemnantsService`, en unidades enteras en vez de kilos. Una orden cancelada o completada ya no compromete
 * nada. Una sola fuente para el buscador de la OP, la acción que agrega un lote y las salidas de PT, que no toman lo
 * comprometido.
 */
class AvailableBatchesService
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Las unidades comprometidas de cada lote en la bodega, en una sola consulta. `$exceptOrderId` descuenta las de una
     * orden: la que se completa toma las suyas.
     *
     * @param  list<int>  $batchIds
     * @return array<int, string> id del lote => unidades comprometidas (solo los que tienen)
     */
    public function committedUnits(array $batchIds, int $warehouseId, ?int $exceptOrderId = null): array
    {
        if ($batchIds === []) {
            return [];
        }

        return $this->committedSources($batchIds, $warehouseId, $exceptOrderId)
            ->groupBy('finished_product_batch_id')
            ->selectRaw('finished_product_batch_id, SUM(units) AS committed_units')
            ->pluck('committed_units', 'finished_product_batch_id')
            ->map(fn (string|int|float $units): string => $this->calculator->round($units, 0))
            ->all();
    }

    /**
     * Las órdenes abiertas que comprometen unidades del lote en la bodega, con cuántas, de la más antigua a la más nueva.
     * Para los avisos: el error dice qué OP las tiene.
     *
     * @return list<array{order_id: int, order_number: string, units: string}>
     */
    public function committedOrders(int $batchId, int $warehouseId, ?int $exceptOrderId = null): array
    {
        return $this->committedSources([$batchId], $warehouseId, $exceptOrderId)
            ->join('production_orders', 'production_orders.id', '=', 'production_order_batch_consumptions.production_order_id')
            ->groupBy('production_orders.id', 'production_orders.order_number')
            ->orderBy('production_orders.id')
            ->selectRaw('production_orders.id AS order_id, production_orders.order_number, SUM(production_order_batch_consumptions.units) AS committed_units')
            ->get()
            ->map(fn (ProductionOrderBatchConsumption $row): array => [
                'order_id' => (int) $row->getAttribute('order_id'),
                'order_number' => (string) $row->getAttribute('order_number'),
                'units' => $this->calculator->round($row->getAttribute('committed_units'), 0),
            ])
            ->all();
    }

    /**
     * Las unidades libres del lote en la bodega. Quien compromete o saca unidades lo llama con el lote bloqueado, para
     * que dos operaciones no tomen a la vez lo mismo.
     */
    public function freeUnits(int $batchId, int $warehouseId, ?int $exceptOrderId = null): string
    {
        return $this->free(
            $this->stock($batchId, $warehouseId),
            $this->committedUnits([$batchId], $warehouseId, $exceptOrderId)[$batchId] ?? '0',
        );
    }

    /**
     * 422 en `$errorKey` si salir `$units` del lote dejaría sin cubrir lo comprometido en órdenes abiertas (sin contar
     * las de `$exceptOrderId`). Con el lote bloqueado por quien llama. El aviso dice qué órdenes tienen las unidades.
     */
    public function ensureFree(int $batchId, int $warehouseId, string $units, ?int $exceptOrderId, string $errorKey): void
    {
        $stock = $this->stock($batchId, $warehouseId);
        $orders = $this->committedOrders($batchId, $warehouseId, $exceptOrderId);
        $committed = $this->calculator->sum(array_column($orders, 'units'), 0);
        $free = $this->free($stock, $committed);

        if ($this->calculator->cmp($units, $free, 0) <= 0) {
            return;
        }

        $message = $orders === []
            ? __('El lote :lot no tiene esas unidades en la bodega: quedan :stock.', [
                'lot' => $this->lotNumber($batchId),
                'stock' => NumberFormat::units($stock),
            ])
            : __('El lote :lot tiene :stock unidades en la bodega y :committed están comprometidas en órdenes abiertas (:orders): quedan :free libres.', [
                'lot' => $this->lotNumber($batchId),
                'stock' => NumberFormat::units($stock),
                'committed' => NumberFormat::units($committed),
                'orders' => collect($orders)
                    ->map(fn (array $order): string => $order['order_number'].' ('.NumberFormat::units($order['units']).')')
                    ->implode(', '),
                'free' => NumberFormat::units($free),
            ]);

        throw ValidationException::withMessages([$errorKey => $message]);
    }

    /**
     * Los lotes que se pueden agregar a la orden: los de su bodega con unidades libres, de cualquier producto (la línea
     * filtra el suyo; «Agregar producto» elige otro), del más antiguo al más nuevo. Cada uno con el producto y el color de
     * la OP que lo fabricó, el lote, la presentación y los kilos por unidad. El costo de traslado, solo con «Ver costos».
     *
     * @return list<array{id: int, product_id: int, product_name: string, lot_number: int|null, source_order_number: string|null, presentation_label: string|null, free_units: string, kg_per_unit: string|null, entry_date: string|null, transfer_unit_cost?: string|null}>
     */
    public function forOrder(ProductionOrder $order, bool $includeCosts): array
    {
        return $this->freeBatches((int) $order->warehouse_id)
            ->map(function (array $row) use ($includeCosts): array {
                /** @var FinishedProductBatch $batch */
                $batch = $row['batch'];
                $sourceOrder = $batch->productionOrder;

                return [
                    'id' => $batch->id,
                    'product_id' => (int) $batch->product_id,
                    'product_name' => ProductionOrder::nameWithColor((string) $batch->product?->name, $sourceOrder?->color),
                    'lot_number' => $sourceOrder?->lot_number,
                    'source_order_number' => $sourceOrder?->order_number,
                    'presentation_label' => $batch->productVariant?->presentation_label,
                    'free_units' => $row['free_units'],
                    'kg_per_unit' => $batch->kgPerUnit(),
                    'entry_date' => $batch->entry_date?->toDateString(),
                    ...($includeCosts ? [
                        'transfer_unit_cost' => $batch->transfer_unit_cost !== null ? (string) $batch->transfer_unit_cost : null,
                    ] : []),
                ];
            })
            ->all();
    }

    /**
     * Los lotes con unidades libres en la bodega, del más antiguo al más nuevo, con lo que necesitan los kilos por unidad.
     *
     * @return Collection<int, array{batch: FinishedProductBatch, free_units: string}>
     */
    private function freeBatches(int $warehouseId): Collection
    {
        $batches = FinishedProductBatch::query()
            ->whereHas('stocks', fn (Builder $stocks) => $stocks->forWarehouse($warehouseId)->available())
            ->with(['product:id,name', 'productVariant', 'productionOrder', 'stocks' => fn ($stocks) => $stocks->forWarehouse($warehouseId)])
            ->get();

        $committed = $this->committedUnits($batches->modelKeys(), $warehouseId);

        return $batches
            ->sortBy([['entry_date', 'asc'], ['id', 'asc']])
            ->map(fn (FinishedProductBatch $batch): array => [
                'batch' => $batch,
                'free_units' => $this->free(
                    (string) ($batch->stocks->first()?->quantity ?? '0'),
                    $committed[$batch->id] ?? '0',
                ),
            ])
            ->filter(fn (array $row): bool => $this->calculator->isPositive($row['free_units']))
            ->values();
    }

    /**
     * Las fuentes de lote sin aplicar de órdenes abiertas en la bodega: la única definición de «comprometido».
     *
     * @param  list<int>  $batchIds
     * @return Builder<ProductionOrderBatchConsumption>
     */
    private function committedSources(array $batchIds, int $warehouseId, ?int $exceptOrderId): Builder
    {
        return ProductionOrderBatchConsumption::query()
            ->planned()
            ->whereIn('production_order_batch_consumptions.finished_product_batch_id', $batchIds)
            ->where('production_order_batch_consumptions.warehouse_id', $warehouseId)
            ->when($exceptOrderId !== null, fn (Builder $query) => $query->where('production_order_batch_consumptions.production_order_id', '!=', $exceptOrderId))
            ->whereHas('productionOrder', fn (Builder $query) => $query->whereIn('status', ProductionOrderStatus::open()));
    }

    private function stock(int $batchId, int $warehouseId): string
    {
        $quantity = FinishedProductBatchStock::query()
            ->where('finished_product_batch_id', $batchId)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity');

        return (string) ($quantity ?? '0');
    }

    private function free(string $stock, string $committed): string
    {
        $free = $this->calculator->sub($stock, $committed, 0);

        return $this->calculator->isNegative($free) ? '0' : $free;
    }

    private function lotNumber(int $batchId): string
    {
        $lot = FinishedProductBatch::query()
            ->whereKey($batchId)
            ->with('productionOrder:id,lot_number')
            ->first(['id', 'production_order_id'])
            ?->productionOrder?->lot_number;

        return $lot !== null ? (string) $lot : "#{$batchId}";
    }
}
