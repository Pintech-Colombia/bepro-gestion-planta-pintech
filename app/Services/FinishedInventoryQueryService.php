<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Filters\FinishedInventoryFilter;
use App\Http\Requests\Inventory\IndexFinishedInventoryRequest;
use App\Models\FinishedInventory;
use App\Models\FinishedProductBatchStock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinishedInventoryQueryService
{
    public function __construct(
        private readonly WarehouseContextService $warehouseContextService,
    ) {}

    /**
     * @return list<int>
     */
    public function accessibleWarehouseIds(User $user): array
    {
        return $this->warehouseContextService
            ->availableWarehouses($user)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function scopedQuery(User $user): Builder
    {
        $warehouseIds = $this->accessibleWarehouseIds($user);

        if ($warehouseIds === []) {
            return FinishedInventory::query()->whereRaw('1 = 0');
        }

        return FinishedInventory::query()->whereIn('warehouse_id', $warehouseIds);
    }

    /**
     * @param  list<int>|null  $variantIds
     * @return Collection<int, string|float>
     */
    public function sumQuantityByVariant(User $user, ?array $variantIds = null): Collection
    {
        $query = $this->scopedQuery($user)
            ->selectRaw('product_variant_id, SUM(quantity) as total_quantity')
            ->whereNotNull('product_variant_id')
            ->groupBy('product_variant_id');

        if ($variantIds !== null) {
            if ($variantIds === []) {
                return collect();
            }

            $query->whereIn('product_variant_id', $variantIds);
        }

        return $query->pluck('total_quantity', 'product_variant_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildIndexData(User $user, IndexFinishedInventoryRequest $request): array
    {
        $baseQuery = $this->scopedQuery($user)
            ->where('quantity', '>', 0)
            ->with([
                'product:id,name,category_id',
                'product.category:id,name',
                'productVariant:id,code,presentation_label,presentation_value',
                'warehouse:id,name,city,type',
            ])
            ->latest('id');

        $query = (new FinishedInventoryFilter($request))->apply($baseQuery);

        // El costo de traslado solo con costs.view (docs/MATRIZ_RBAC.md, principio 1); la OP enlaza solo con
        // production_orders.view.
        $canViewCosts = $user->can(Permission::CostsView->value);

        $inventory = $query
            ->paginate(20)
            ->onEachSide(1)
            ->withQueryString();

        $lotsByRow = $this->lotsForRows(collect($inventory->items()), $canViewCosts);

        $inventory->through(fn (FinishedInventory $row): array => [
            'id' => $row->id,
            'quantity' => $row->quantity,
            'lots' => $lotsByRow[$this->lotKey($row->product_variant_id, $row->warehouse_id)] ?? [],
            'product' => $row->product ? [
                'id' => $row->product->id,
                'name' => $row->product->name,
                'category' => $row->product->category ? [
                    'name' => $row->product->category->name,
                ] : null,
            ] : null,
            'variant' => $row->productVariant ? [
                'id' => $row->productVariant->id,
                'code' => $row->productVariant->code,
                'presentation_label' => $row->productVariant->presentation_label,
                'presentation_value' => $row->productVariant->presentation_value,
            ] : null,
            'warehouse' => $row->warehouse ? [
                'id' => $row->warehouse->id,
                'name' => $row->warehouse->name,
                'city' => $row->warehouse->city,
                'type' => $row->warehouse->type->value,
            ] : null,
        ]);

        $warehouses = $this->warehouseContextService
            ->availableWarehouses($user)
            ->map(fn ($warehouse): array => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'city' => $warehouse->city,
            ])
            ->values();

        $warehouseOptions = $warehouses->map(fn ($w) => [
            'value' => (string) $w['id'],
            'label' => $w['name'],
        ])->all();

        $productId = $request->validated('product_id');

        return [
            'inventory' => $inventory,
            'warehouseOptions' => $warehouseOptions,
            'filters' => array_merge($request->validated(), [
                'product_name' => $productId !== null ? Product::query()->whereKey($productId)->value('name') : null,
            ]),
            // El producto enlaza a su detalle, que pide otro permiso: sin él, el nombre queda como texto.
            'can' => [
                'viewProducts' => $user->can(Permission::ProductsView->value),
                'viewProductionOrders' => $user->can(Permission::ProductionOrdersView->value),
                'viewCosts' => $canViewCosts,
            ],
        ];
    }

    /**
     * Lotes con stock de las filas de la página (presentación y bodega), en una sola consulta (stock → lote → OP),
     * del más antiguo al más nuevo. Las filas ya vienen limitadas a las bodegas del usuario.
     *
     * @param  Collection<int, FinishedInventory>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function lotsForRows(Collection $rows, bool $canViewCosts): array
    {
        $rows = $rows->filter(fn (FinishedInventory $row): bool => $row->product_variant_id !== null);

        if ($rows->isEmpty()) {
            return [];
        }

        $rowKeys = $rows
            ->mapWithKeys(fn (FinishedInventory $row): array => [$this->lotKey($row->product_variant_id, $row->warehouse_id) => true])
            ->all();

        $stocks = FinishedProductBatchStock::query()
            ->join('finished_product_batches', 'finished_product_batches.id', '=', 'finished_product_batch_stocks.finished_product_batch_id')
            ->join('production_orders', 'production_orders.id', '=', 'finished_product_batches.production_order_id')
            ->where('finished_product_batch_stocks.quantity', '>', 0)
            ->whereIn('finished_product_batch_stocks.warehouse_id', $rows->pluck('warehouse_id')->unique()->values()->all())
            ->whereIn('finished_product_batches.product_variant_id', $rows->pluck('product_variant_id')->unique()->values()->all())
            ->orderBy('finished_product_batches.entry_date')
            ->orderBy('finished_product_batches.id')
            ->select([
                'finished_product_batch_stocks.id',
                'finished_product_batch_stocks.warehouse_id',
                'finished_product_batch_stocks.quantity',
                'finished_product_batches.product_variant_id',
                'finished_product_batches.entry_date',
                'finished_product_batches.transfer_unit_cost',
                'finished_product_batches.transfer_cost_estimated',
                'production_orders.id as production_order_id',
                'production_orders.order_number',
                'production_orders.lot_number',
                // El color de la OP (productos genéricos a pedido): en una misma fila puede haber lotes de colores
                // distintos, y solo la lista de lotes lo muestra.
                'production_orders.color',
            ])
            ->withCasts([
                'entry_date' => 'date',
                'transfer_unit_cost' => 'decimal:4',
                'transfer_cost_estimated' => 'boolean',
                'production_order_id' => 'integer',
                'lot_number' => 'integer',
            ])
            ->get();

        $lots = [];

        foreach ($stocks as $stock) {
            $key = $this->lotKey((int) $stock->getAttribute('product_variant_id'), $stock->warehouse_id);

            // Las dos listas de ids cruzan presentaciones y bodegas: solo cuentan las parejas de la página.
            if (! isset($rowKeys[$key])) {
                continue;
            }

            $lot = [
                'id' => $stock->id,
                'lot_number' => $stock->getAttribute('lot_number'),
                'color' => $stock->getAttribute('color'),
                'entry_date' => $stock->getAttribute('entry_date')?->format('Y-m-d'),
                'quantity' => $stock->quantity,
                'production_order' => [
                    'id' => $stock->getAttribute('production_order_id'),
                    'order_number' => $stock->getAttribute('order_number'),
                ],
            ];

            if ($canViewCosts) {
                $lot['transfer_unit_cost'] = $stock->getAttribute('transfer_unit_cost');
                $lot['transfer_cost_estimated'] = $stock->getAttribute('transfer_cost_estimated');
            }

            $lots[$key][] = $lot;
        }

        return $lots;
    }

    private function lotKey(?int $variantId, int $warehouseId): string
    {
        return $variantId.':'.$warehouseId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inventoryRowsForProduct(User $user, Product $product): array
    {
        return $this->scopedQuery($user)
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->with([
                'warehouse:id,name,city,type',
                'productVariant:id,code,presentation_label,presentation_value',
                'productVariant.unitOfMeasure:id,symbol',
            ])
            ->orderBy('warehouse_id')
            ->orderBy('product_variant_id')
            ->get()
            ->map(fn (FinishedInventory $row): array => [
                'id' => $row->id,
                'quantity' => $row->quantity,
                'warehouse' => $row->warehouse ? [
                    'id' => $row->warehouse->id,
                    'name' => $row->warehouse->name,
                    'city' => $row->warehouse->city,
                    'type' => $row->warehouse->type->value,
                ] : null,
                'variant' => $row->productVariant ? [
                    'id' => $row->productVariant->id,
                    'code' => $row->productVariant->code,
                    'presentation_label' => $row->productVariant->presentation_label,
                    'presentation_value' => $row->productVariant->presentation_value,
                    'unit_symbol' => $row->productVariant->unitOfMeasure?->symbol,
                ] : null,
            ])
            ->values()
            ->all();
    }
}
