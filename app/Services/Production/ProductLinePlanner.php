<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\NumberType;
use App\Enums\ProductionOrderProductLineOrigin;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionOrderProductLine;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;
use App\Services\AvailableBatchesService;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Las líneas de «Productos a usar» de una OP: el producto terminado que lleva como ingrediente.
 *
 * Al crear la OP, cada línea de producto de la fórmula da una línea con sus kilos planeados (la cantidad por galón en kg
 * × los galones de la orden) y se preseleccionan sus fuentes: primero los saldos libres del producto en la bodega de la
 * orden, después los lotes con unidades libres, en los dos casos del más antiguo al más nuevo; de un lote se toman las
 * unidades enteras que cubren lo que falta. Si no alcanza, la orden se crea igual (crear no exige stock) y la línea queda
 * con lo que falta: completar sí exige que tenga fuentes.
 *
 * Una fuente de un producto que la orden no tiene crea una línea «agregada», sin plan; quitar la última fuente de una
 * línea agregada la quita.
 */
class ProductLinePlanner
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly AvailableBatchesService $availableBatches,
        private readonly RemnantDrawdown $remnantDrawdown,
    ) {}

    /**
     * Crea las líneas de producto de la fórmula en la orden recién creada y les preselecciona fuentes. Dentro de la
     * transacción que crea la orden: los saldos y los lotes candidatos se bloquean (saldos y después lotes, por id, el
     * orden de completar) para que otra orden no comprometa a la vez lo mismo.
     *
     * @return Collection<int, ProductionOrderProductLine>
     */
    public function planFromFormula(ProductionOrder $order, Formula $formula, int $userId): Collection
    {
        $formula->loadMissing(['details.unitOfMeasure', 'details.product']);

        $lines = $formula->details
            ->filter(fn (FormulaDetail $detail): bool => $detail->isProductLine())
            ->map(fn (FormulaDetail $detail): ProductionOrderProductLine => ProductionOrderProductLine::create([
                'production_order_id' => $order->id,
                'product_id' => $detail->product_id,
                'step_order' => $detail->step_order,
                'planned_kg' => $this->plannedKg($detail, (string) $order->quantity),
                'origin' => ProductionOrderProductLineOrigin::Formula,
            ]))
            ->values();

        if ($lines->isNotEmpty()) {
            $this->preselect($order, $lines, $userId);
        }

        return $lines;
    }

    /**
     * Los kilos de una línea de producto de la fórmula para la orden: cantidad por galón × equivalencia a kilos de su
     * unidad × galones de la orden. La unidad de una línea de producto es de peso (la fórmula lo exige al guardarse).
     */
    public function plannedKg(FormulaDetail $detail, string $orderQuantity): string
    {
        $toKg = $detail->unitOfMeasure?->to_kg_conversion;
        $productName = $detail->product->name ?? "#{$detail->product_id}";

        if ($toKg === null) {
            throw ValidationException::withMessages([
                'formula_id' => __('La línea de :product de la fórmula no está en una unidad de peso.', ['product' => $productName]),
            ]);
        }

        $kilos = $this->calculator->round(
            $this->calculator->mul($this->calculator->mul((string) $detail->quantity, (string) $toKg, 10), $orderQuantity, 10),
            4,
        );

        // La cantidad por galón y los galones caben cada uno en su regla; su producto puede no caber.
        NumberType::Quantity->ensureFits(
            $kilos,
            'quantity',
            __('Los kilos de :product para esta orden no caben en el registro. Revisa la cantidad a producir.', ['product' => $productName]),
        );

        return $kilos;
    }

    /**
     * La línea a la que se agrega una fuente del producto: la indicada (de la orden y del mismo producto), la primera de
     * ese producto, o una «agregada» nueva. Con la orden bloqueada por quien llama.
     */
    public function lineFor(ProductionOrder $lockedOrder, int $productId, ?int $lineId, string $errorKey): ProductionOrderProductLine
    {
        if ($lineId !== null) {
            $line = ProductionOrderProductLine::query()
                ->where('production_order_id', $lockedOrder->id)
                ->find($lineId);

            if ($line === null || (int) $line->product_id !== $productId) {
                throw ValidationException::withMessages([
                    $errorKey => __('La fuente tiene que ser del mismo producto de la línea.'),
                ]);
            }

            return $line;
        }

        return ProductionOrderProductLine::query()
            ->where('production_order_id', $lockedOrder->id)
            ->where('product_id', $productId)
            ->orderBy('id')
            ->first()
            ?? ProductionOrderProductLine::create([
                'production_order_id' => $lockedOrder->id,
                'product_id' => $productId,
                'step_order' => null,
                'planned_kg' => null,
                'origin' => ProductionOrderProductLineOrigin::Added,
            ]);
    }

    /**
     * Quita una línea agregada que se quedó sin fuentes: nació con su primera fuente y sin ellas no dice nada. Las de la
     * fórmula se quedan (completar exige que tengan alguna).
     */
    public function pruneIfEmpty(?int $lineId): void
    {
        if ($lineId === null) {
            return;
        }

        $line = ProductionOrderProductLine::query()->find($lineId);

        if ($line === null || ! $line->isAdded()
            || $line->remnantConsumptions()->exists()
            || $line->batchConsumptions()->exists()) {
            return;
        }

        $line->delete();
    }

    /**
     * @param  Collection<int, ProductionOrderProductLine>  $lines
     */
    private function preselect(ProductionOrder $order, Collection $lines, int $userId): void
    {
        $warehouseId = (int) $order->warehouse_id;
        $productIds = $lines->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        // Saldos y después lotes, cada grupo por id: el orden de bloqueo de completar.
        $remnants = ProductionRemnant::query()
            ->available()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
        $plannedKg = $this->availableRemnants->plannedKilos($remnants->modelKeys());
        $freeKg = $remnants->mapWithKeys(fn (ProductionRemnant $remnant): array => [
            $remnant->id => $this->nonNegative($this->calculator->sub((string) $remnant->available_quantity_kg, $plannedKg[$remnant->id] ?? '0', 4)),
        ])->all();

        $batches = $this->availableBatches->forProducts($productIds, $warehouseId, lock: true);
        $freeUnits = $batches->mapWithKeys(fn (array $row): array => [$row['batch']->id => $row['free_units']])->all();

        foreach ($lines as $line) {
            $remaining = (string) ($line->planned_kg ?? '0');

            foreach ($remnants->where('product_id', $line->product_id) as $remnant) {
                if (! $this->calculator->isPositive($remaining)) {
                    break;
                }

                $take = $this->calculator->min($freeKg[$remnant->id], $remaining, 4);
                $estimate = $this->remnantDrawdown->estimate($remnant, $take);

                if (! $this->calculator->isPositive($take) || ! $this->calculator->isPositive($estimate['quantity_gallons'])) {
                    continue;
                }

                RemnantConsumption::create([
                    'remnant_id' => $remnant->id,
                    'target_order_id' => $order->id,
                    'production_order_product_line_id' => $line->id,
                    'quantity_kg' => $take,
                    'quantity_gallons' => $estimate['quantity_gallons'],
                    'consumed_cost' => null,
                    'consumed_by' => $userId,
                    'consumed_at' => now(),
                    'applied_at' => null,
                ]);

                $freeKg[$remnant->id] = $this->calculator->sub($freeKg[$remnant->id], $take, 4);
                $remaining = $this->calculator->sub($remaining, $take, 4);
            }

            foreach ($batches->filter(fn (array $row): bool => (int) $row['batch']->product_id === (int) $line->product_id) as $row) {
                if (! $this->calculator->isPositive($remaining)) {
                    break;
                }

                $batch = $row['batch'];
                $kgPerUnit = $batch->kgPerUnit();
                $available = $freeUnits[$batch->id];

                if ($kgPerUnit === null || ! $this->calculator->isPositive($kgPerUnit) || ! $this->calculator->isPositive($available)) {
                    continue;
                }

                $units = $this->calculator->min($this->unitsCovering($remaining, $kgPerUnit), $available, 0);

                ProductionOrderBatchConsumption::create([
                    'production_order_id' => $order->id,
                    'production_order_product_line_id' => $line->id,
                    'finished_product_batch_id' => $batch->id,
                    'warehouse_id' => $warehouseId,
                    'units' => $units,
                    'created_by' => $userId,
                ]);

                $freeUnits[$batch->id] = $this->calculator->sub($available, $units, 0);
                $remaining = $this->nonNegative($this->calculator->sub($remaining, $this->calculator->mul($units, $kgPerUnit, 4), 4));
            }
        }
    }

    /** Las unidades enteras que cubren `$kilos`: techo de kilos ÷ kilos por unidad. */
    private function unitsCovering(string $kilos, string $kgPerUnit): string
    {
        $exact = $this->calculator->div($kilos, $kgPerUnit, 8);
        $whole = $this->calculator->add($exact, '0', 0);

        return $this->calculator->cmp($exact, $whole, 8) > 0
            ? $this->calculator->add($whole, '1', 0)
            : $whole;
    }

    private function nonNegative(string $amount): string
    {
        return $this->calculator->isNegative($amount) ? '0' : $amount;
    }
}
