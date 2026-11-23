<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\NumberType;
use App\Enums\ProductionOrderProductLineOrigin;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderProductLine;
use App\Services\DecimalCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Las líneas de «Productos a usar» de una OP: el producto terminado que lleva como ingrediente.
 *
 * Al crear la OP, cada línea de producto de la fórmula da una línea con sus kilos planeados (la cantidad por galón en kg
 * × los galones de la orden), **sin fuentes**: de dónde sale cada kilo lo elige una persona en la orden (saldos o lotes de
 * la bodega). Elegir solo dejaba combinaciones que la planta no usa (4 g de un segundo saldo, un cuñete entero por un
 * resto) y comprometía stock desde que se creaba la orden. Enviar a revisión y completar exigen que cada línea de la
 * fórmula tenga fuentes.
 *
 * Una fuente de un producto que la orden no tiene crea una línea «agregada», sin plan; quitar la última fuente de una
 * línea agregada la quita.
 */
class ProductLinePlanner
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Crea las líneas de producto de la fórmula en la orden recién creada, con sus kilos planeados y sin fuentes.
     *
     * @return Collection<int, ProductionOrderProductLine>
     */
    public function planFromFormula(ProductionOrder $order, Formula $formula): Collection
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
}
