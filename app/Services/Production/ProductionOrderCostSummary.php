<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Actions\Production\PreviewProductionOrderCostsAction;
use App\Enums\FinishedInventoryMovementReason;
use App\Enums\InventoryMovementType;
use App\Enums\ProductionOrderStatus;
use App\Models\FinishedInventoryMovement;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\RemnantConsumption;
use App\Services\DecimalCalculator;
use App\Services\Pricing\ProductionCostCalculatorService;
use App\Services\VariantSalesPriceService;

/**
 * El resumen de costos de una OP, en un solo lugar: la tarjeta del detalle, la vista previa mientras se digita, el PDF y
 * el Excel salen de aquí, con la misma cuenta en todos los estados.
 *
 * - Pendiente y abierta: estimado, con la vista previa (`PreviewProductionOrderCostsAction`) y el CIF del producto hoy.
 * - Completada: real, desde lo guardado al completar, con el CIF que la OP guardó ese día.
 * - Cancelada: sin costos.
 *
 * Todo es texto decimal calculado con DecimalCalculator; la pantalla solo lo pinta. Los montos van sin CIF salvo los
 * que lo dicen («with_cif»). La comparación es contra la lista de precios **de hoy** (costo teórico de la fórmula de
 * referencia y precio interno de cada presentación): sirve para decidir precios ahora.
 *
 * @phpstan-type CostSummary array{
 *   basis: 'estimated'|'actual',
 *   cif_estimated: bool,
 *   bulk: array{total: string, raw_materials: string, line_adjustments: string, remnants_used: string, products_used: string},
 *   yield_gallons: string,
 *   cost_per_gallon: string|null,
 *   cost_per_gallon_with_cif: string|null,
 *   cost_per_kg: string|null,
 *   packaged: array{total: string, bulk: string, packaging_materials: string, total_with_cif: string},
 *   remnant: array{gallons: string, cost_with_cif: string|null, total_with_remnant: string|null}|null,
 *   packaging: list<array{id: int, units: string, unit_cost: string, unit_cost_with_cif: string, total_cost: string}>,
 *   comparison: array{
 *     reference_cost_per_gallon: string|null,
 *     reference_difference_percentage: string|null,
 *     uses_reference_formula: bool,
 *     list_margin_percentage: string|null,
 *     presentations: list<array{id: int, presentation_label: string|null, unit_cost_with_cif: string, list_price: string|null, list_difference_percentage: string|null, real_margin_percentage: string|null}>
 *   }
 * }
 */
class ProductionOrderCostSummary
{
    public function __construct(
        private readonly PreviewProductionOrderCostsAction $preview,
        private readonly ProductionCostCalculatorService $costCalculator,
        private readonly VariantSalesPriceService $salesPrice,
        private readonly DecimalCalculator $calculator,
        private readonly ProductionOrderCompletionLines $completionLines,
    ) {}

    /**
     * El resumen con lo guardado en la orden: el detalle, el PDF y el Excel. Con la orden abierta o pendiente es lo que
     * la pantalla muestra al abrir (lo planeado o lo que la planta ya guardó), antes de que se digite algo.
     *
     * @return CostSummary|null null si la orden está cancelada
     */
    public function forOrder(ProductionOrder $order): ?array
    {
        return match ($order->status) {
            ProductionOrderStatus::Cancelled => null,
            ProductionOrderStatus::Completed => $this->completed($order),
            default => $this->estimated(
                $order,
                $this->preview->calculate($order, $this->storedIngredients($order), $this->storedPackaging($order), $this->storedRemnantGallons($order)),
                $order->density_kg_per_gallon !== null ? (string) $order->density_kg_per_gallon : null,
            ),
        };
    }

    /**
     * La vista previa con lo que se está digitando (cantidades en la unidad de cada línea). Una orden completada
     * devuelve lo guardado y una cancelada, nada: lo digitado ya no cambia sus costos.
     *
     * @param  array<int, array{id:int,actual_quantity:string|int|float}>  $ingredients
     * @param  array<int, array<string, mixed>>  $packaging
     * @return array{ingredients: list<array<string, mixed>>, summary: CostSummary|null}
     */
    public function preview(ProductionOrder $order, array $ingredients, array $packaging, ?string $remnantGallons, ?string $density): array
    {
        if (! in_array($order->status, ProductionOrderStatus::open(), true)) {
            return ['ingredients' => [], 'summary' => $this->forOrder($order)];
        }

        $result = $this->preview->execute($order, $ingredients, $packaging, $remnantGallons);

        return [
            'ingredients' => $result['ingredients'],
            'summary' => $this->estimated($order, $result, $density ?? ($order->density_kg_per_gallon !== null ? (string) $order->density_kg_per_gallon : null)),
        ];
    }

    /**
     * @param  array<string, mixed>  $result  lo que devuelve PreviewProductionOrderCostsAction::calculate()
     * @return CostSummary
     */
    private function estimated(ProductionOrder $order, array $result, ?string $density): array
    {
        $packaging = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'units' => (string) $row['actual_units'],
            'bulk_unit_cost' => (string) $row['bulk_unit_cost'],
            'unit_cost' => (string) $row['cost_price'],
        ], $result['packaging']);

        return $this->assemble(
            order: $order,
            basis: 'estimated',
            cifPercentage: $result['cif_percentage'],
            cifEstimated: false,
            bulk: [
                'total' => $result['total_bulk_cost'],
                'raw_materials' => $result['raw_materials_cost'],
                'line_adjustments' => $result['line_adjustments_cost'],
                'remnants_used' => $result['remnants_cost'],
                'products_used' => $result['products_cost'],
            ],
            yieldGallons: $result['real_yield'],
            costPerGallon: $result['bulk_cost_per_unit'],
            density: $density,
            packaging: $packaging,
            remnantGallons: $result['remnant_gallons'],
            remnantCostWithCif: $result['remnant_bulk_cost'],
        );
    }

    /**
     * Lo guardado al completar: el granel total es el de `production_costs` (MP + ajustes de línea + saldos y lotes de PT
     * usados), y cada presentación cuesta lo de su entrada de PT. Sin fila de costo (solo se crea con costo positivo), el
     * total es la suma de las partes, que entonces es cero.
     *
     * @return CostSummary
     */
    private function completed(ProductionOrder $order): array
    {
        $order->loadMissing(['product', 'details', 'remnantConsumptions', 'batchConsumptions', 'packagingPlans.productVariant', 'finishedInventoryMovements', 'remnant']);

        $rawMaterials = $this->calculator->sum(
            $order->details->map(fn ($detail): string => (string) ($detail->total_cost ?? '0'))->all(),
        );
        $remnantsUsed = $this->calculator->sum(
            $order->remnantConsumptions
                ->filter(fn (RemnantConsumption $line): bool => $line->isApplied() && $line->consumed_cost !== null)
                ->map(fn (RemnantConsumption $line): string => (string) $line->consumed_cost)
                ->all(),
        );
        $productsUsed = $this->calculator->sum(
            $order->batchConsumptions
                ->filter(fn (ProductionOrderBatchConsumption $source): bool => $source->isApplied() && $source->consumed_cost !== null)
                ->map(fn (ProductionOrderBatchConsumption $source): string => (string) $source->consumed_cost)
                ->all(),
        );
        $knownParts = $this->calculator->sum([$rawMaterials, $remnantsUsed, $productsUsed], 4);
        $storedTotal = ProductionCost::query()->where('production_order_id', $order->id)->value('cost');
        $total = $storedTotal !== null ? (string) $storedTotal : $knownParts;
        // El costo de los ajustes no se guarda por línea: es lo que el total tiene además de la MP, los saldos y los lotes.
        // Cada parte se guardó con 4 decimales y el total es su suma exacta, así que la resta no depende de redondeos.
        $lineAdjustments = $this->calculator->sub($total, $knownParts, 4);

        $yield = (string) ($order->yield_real_quantity ?? $order->actual_quantity ?? '0');
        // La misma cuenta del reparto al completar: sin costo o sin rendimiento no hay costo por galón.
        $costPerGallon = $this->calculator->isPositive($total) && $this->calculator->isPositive($yield)
            ? $this->calculator->div($total, $yield, 4)
            : null;

        $entryCostByVariant = $order->finishedInventoryMovements
            ->filter(fn (FinishedInventoryMovement $movement): bool => $movement->type === InventoryMovementType::Entry
                && $movement->reason === FinishedInventoryMovementReason::Production)
            ->keyBy('product_variant_id');

        $packaging = $order->packagingPlans
            ->filter(fn (ProductionOrderPackagingPlan $plan): bool => $plan->actual_units !== null
                && $this->calculator->isPositive((string) $plan->actual_units))
            ->map(fn (ProductionOrderPackagingPlan $plan): array => [
                'id' => (int) $plan->id,
                'units' => (string) $plan->actual_units,
                'bulk_unit_cost' => $costPerGallon !== null
                    ? $this->calculator->mul($costPerGallon, (string) ($plan->productVariant?->presentation_value ?? '1'), 4)
                    : '0',
                'unit_cost' => (string) ($entryCostByVariant->get($plan->product_variant_id)?->cost_price ?? '0'),
            ])
            ->values()
            ->all();

        $remnant = $order->remnant;

        $cifPercentage = $order->cif_percentage !== null ? (string) $order->cif_percentage : null;
        $cifEstimated = (bool) $order->cif_percentage_estimated;

        // Sin CIF ni marca: la completó una imagen anterior a la columna (entre la migración y el cambio de contenedores,
        // o tras volver atrás un despliegue) y el relleno ya había corrido. Se usa el CIF de hoy del producto, marcado
        // como estimado; es solo lectura, la orden no se toca.
        if ($cifPercentage === null && ! $cifEstimated) {
            $productCif = $order->product?->cif_percentage;
            $cifPercentage = $productCif !== null ? (string) $productCif : null;
            $cifEstimated = true;
        }

        return $this->assemble(
            order: $order,
            basis: 'actual',
            cifPercentage: $cifPercentage,
            cifEstimated: $cifEstimated,
            bulk: [
                'total' => $total,
                'raw_materials' => $rawMaterials,
                'line_adjustments' => $lineAdjustments,
                'remnants_used' => $remnantsUsed,
                'products_used' => $productsUsed,
            ],
            yieldGallons: $yield,
            costPerGallon: $costPerGallon,
            density: $order->density_kg_per_gallon !== null ? (string) $order->density_kg_per_gallon : null,
            packaging: $packaging,
            remnantGallons: $remnant !== null ? (string) $remnant->original_quantity_gallons : null,
            // El costo que el saldo guardó al nacer (con el CIF de ese día): el mismo con que lo usa otra orden.
            remnantCostWithCif: $remnant?->cost_per_gallon !== null
                ? $this->calculator->mul((string) $remnant->original_quantity_gallons, (string) $remnant->cost_per_gallon, 4)
                : null,
        );
    }

    /**
     * Lo que comparten los dos: costos con CIF, costo de lo envasado, saldo y comparación con la lista de hoy.
     *
     * @param  'estimated'|'actual'  $basis
     * @param  array{total: string, raw_materials: string, line_adjustments: string, remnants_used: string, products_used: string}  $bulk
     * @param  list<array{id: int, units: string, bulk_unit_cost: string, unit_cost: string}>  $packaging
     * @return CostSummary
     */
    private function assemble(
        ProductionOrder $order,
        string $basis,
        ?string $cifPercentage,
        bool $cifEstimated,
        array $bulk,
        string $yieldGallons,
        ?string $costPerGallon,
        ?string $density,
        array $packaging,
        ?string $remnantGallons,
        ?string $remnantCostWithCif,
    ): array {
        $order->loadMissing(['product', 'formula', 'packagingPlans.productVariant']);

        $withCif = fn (string $cost): string => $this->costCalculator->transferCost($cost, $cifPercentage);

        $packagedTotal = '0';
        $packagedBulk = '0';
        $packagingRows = [];

        foreach ($packaging as $row) {
            $rowTotal = $this->calculator->mul($row['units'], $row['unit_cost'], 4);
            $packagedTotal = $this->calculator->add($packagedTotal, $rowTotal, 4);
            $packagedBulk = $this->calculator->add($packagedBulk, $this->calculator->mul($row['units'], $row['bulk_unit_cost'], 4), 4);

            $packagingRows[] = [
                'id' => $row['id'],
                'units' => $row['units'],
                'unit_cost' => $row['unit_cost'],
                'unit_cost_with_cif' => $withCif($row['unit_cost']),
                'total_cost' => $rowTotal,
            ];
        }

        $packagedWithCif = $withCif($packagedTotal);

        $remnant = null;

        if ($this->completionLines->hasRemnant($remnantGallons)) {
            $remnant = [
                'gallons' => (string) $remnantGallons,
                'cost_with_cif' => $remnantCostWithCif,
                'total_with_remnant' => $remnantCostWithCif !== null
                    ? $this->calculator->add($packagedWithCif, $remnantCostWithCif, 4)
                    : null,
            ];
        }

        return [
            'basis' => $basis,
            'cif_estimated' => $cifEstimated,
            // Con 4 decimales también las partes en cero ('0' de una suma vacía), como las demás.
            'bulk' => array_map(fn (string $amount): string => $this->calculator->add($amount, '0', 4), $bulk),
            'yield_gallons' => $yieldGallons,
            'cost_per_gallon' => $costPerGallon,
            'cost_per_gallon_with_cif' => $costPerGallon !== null ? $withCif($costPerGallon) : null,
            'cost_per_kg' => $costPerGallon !== null && $density !== null && $this->calculator->isPositive($density)
                ? $this->calculator->div($costPerGallon, $density, 4)
                : null,
            'packaged' => [
                'total' => $packagedTotal,
                'bulk' => $packagedBulk,
                'packaging_materials' => $this->calculator->sub($packagedTotal, $packagedBulk, 4),
                'total_with_cif' => $packagedWithCif,
            ],
            'remnant' => $remnant,
            'packaging' => $packagingRows,
            'comparison' => $this->comparison($order, $costPerGallon, $packagingRows),
        ];
    }

    /**
     * A: costo por galón contra el teórico de la fórmula de referencia (`products.current_cost`). B: costo con CIF de
     * cada presentación contra su precio interno de la lista. C: el margen que queda vendiendo al precio de venta de la
     * lista, solo si el producto tiene margen. Todo contra la lista de hoy.
     *
     * @param  list<array{id: int, units: string, unit_cost: string, unit_cost_with_cif: string, total_cost: string}>  $packagingRows
     * @return array{reference_cost_per_gallon: string|null, reference_difference_percentage: string|null, uses_reference_formula: bool, list_margin_percentage: string|null, presentations: list<array{id: int, presentation_label: string|null, unit_cost_with_cif: string, list_price: string|null, list_difference_percentage: string|null, real_margin_percentage: string|null}>}
     */
    private function comparison(ProductionOrder $order, ?string $costPerGallon, array $packagingRows): array
    {
        $product = $order->product;
        $referenceCost = $product?->current_cost !== null && $this->calculator->isPositive((string) $product->current_cost)
            ? (string) $product->current_cost
            : null;
        $margin = $product?->sales_margin !== null && $this->calculator->isPositive((string) $product->sales_margin)
            ? (string) $product->sales_margin
            : null;
        $plansById = $order->packagingPlans->keyBy('id');

        $presentations = array_map(function (array $row) use ($plansById, $margin): array {
            $variant = $plansById->get($row['id'])?->productVariant;
            $listPrice = $variant?->current_price !== null && $this->calculator->isPositive((string) $variant->current_price)
                ? (string) $variant->current_price
                : null;
            $salesPrice = $listPrice !== null && $margin !== null
                ? $this->salesPrice->applySalesMargin($listPrice, $margin)
                : null;

            return [
                'id' => $row['id'],
                'presentation_label' => $variant?->presentation_label,
                'unit_cost_with_cif' => $row['unit_cost_with_cif'],
                'list_price' => $listPrice,
                'list_difference_percentage' => $listPrice !== null ? $this->differencePercentage($row['unit_cost_with_cif'], $listPrice) : null,
                'real_margin_percentage' => $salesPrice !== null && $this->calculator->isPositive($salesPrice)
                    ? $this->salesPrice->resolveMarginFromSalesPrice($row['unit_cost_with_cif'], $salesPrice)
                    : null,
            ];
        }, $packagingRows);

        return [
            'reference_cost_per_gallon' => $referenceCost,
            'reference_difference_percentage' => $referenceCost !== null && $costPerGallon !== null
                ? $this->differencePercentage($costPerGallon, $referenceCost)
                : null,
            'uses_reference_formula' => (bool) $order->formula?->is_price_reference,
            'list_margin_percentage' => $margin,
            'presentations' => $presentations,
        ];
    }

    /** (real − referencia) ÷ referencia × 100, con 2 decimales. Positivo: costó más que la referencia. */
    private function differencePercentage(string $actual, string $reference): string
    {
        return $this->calculator->round(
            $this->calculator->mul($this->calculator->div($this->calculator->sub($actual, $reference, 8), $reference, 8), '100', 8),
            2,
        );
    }

    /**
     * Las líneas como las muestra la pantalla al abrir: lo real guardado o, si aún no hay, lo planeado. Ya en la unidad
     * de la materia prima.
     *
     * @return list<array{id:int,actual_quantity:string}>
     */
    private function storedIngredients(ProductionOrder $order): array
    {
        $order->loadMissing('details');

        return $order->details
            ->map(fn ($detail): array => [
                'id' => (int) $detail->id,
                'actual_quantity' => (string) ($detail->actual_quantity ?? $detail->planned_quantity),
            ])
            ->values()
            ->all();
    }

    /**
     * Las presentaciones como las muestra la pantalla al abrir: las unidades reales guardadas o, si aún no hay, las
     * planeadas. Los envases nuevos y las etiquetas los toma del plan (`packagingConsumption`).
     *
     * @return list<array{id:int,actual_units:string}>
     */
    private function storedPackaging(ProductionOrder $order): array
    {
        $order->loadMissing('packagingPlans');

        return $order->packagingPlans
            ->map(fn (ProductionOrderPackagingPlan $plan): array => [
                'id' => (int) $plan->id,
                'actual_units' => (string) ($plan->actual_units ?? $plan->planned_units),
            ])
            ->values()
            ->all();
    }

    private function storedRemnantGallons(ProductionOrder $order): ?string
    {
        return $this->completionLines->remnantGallons($order->remnant_quantity_kg, $order->density_kg_per_gallon);
    }
}
