<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\FormulaLineCostingBasis;
use App\Enums\ProductionOrderStatus;
use App\Models\FormulaDetail;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Services\DecimalCalculator;
use Illuminate\Support\Collection;

/**
 * El costo teórico por kilo de un producto usado como ingrediente (P13 y P14 de «Alcance — PT consumible»), en un solo
 * lugar: lo usan el recálculo del costo de la fórmula y la ficha de la fórmula.
 *
 * Sale de la **fórmula del producto fuente** a precios de hoy (su `current_cost`), con **su CIF y sin su margen**:
 * - a granel: granel por galón × (1 + CIF) ÷ peso por galón;
 * - con una presentación: su costo (granel × galones + envase + etiqueta) × (1 + CIF) ÷ (galones × peso por galón).
 *
 * Peso por galón: el de referencia del producto; si no tiene, la densidad de su última OP completada.
 */
class ProductLineTheoreticalCost
{
    public const REASON_MISSING_BASIS = 'missing_basis';

    public const REASON_MISSING_DENSITY = 'missing_density';

    public const REASON_MISSING_SOURCE_COST = 'missing_source_cost';

    public const REASON_INACTIVE_PRESENTATION = 'inactive_presentation';

    private const SCALE = 6;

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductionCostCalculatorService $productionCostCalculator,
    ) {}

    /**
     * El costo por kilo de cada línea de producto, por id de línea, con su desglose o el motivo por el que no se puede.
     * Lee **sus propios** productos y presentaciones (filas completas, una consulta para cada uno): no confía en las
     * relaciones que traiga la línea, que quien llama pudo cargar con pocas columnas para la pantalla.
     *
     * @param  iterable<FormulaDetail>  $lines
     * @return array<int, array{cost_per_kg: string|null, bulk_per_kg: string|null, packaging_per_kg: string|null, density: string|null, density_source: string|null, density_order_number: string|null, reason: string|null}>
     */
    public function forLines(iterable $lines): array
    {
        /** @var Collection<int, FormulaDetail> $productLines */
        $productLines = collect($lines)->filter(fn (FormulaDetail $line): bool => $line->isProductLine())->values();

        if ($productLines->isEmpty()) {
            return [];
        }

        $products = Product::query()->whereKey($productLines->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $variants = ProductVariant::query()->whereKey($productLines->pluck('costing_variant_id')->filter()->unique()->all())->get()->keyBy('id');
        $lastLots = $this->lastLotDensities($products->filter(fn (Product $product): bool => $product->reference_density_kg_per_gallon === null)->keys()->all());

        $costs = [];

        foreach ($productLines as $line) {
            $costs[(int) $line->id] = $this->costFor(
                $line,
                $products->get((int) $line->product_id),
                $line->costing_variant_id !== null ? $variants->get((int) $line->costing_variant_id) : null,
                $lastLots,
            );
        }

        return $costs;
    }

    /**
     * El texto del motivo, para la pantalla y el log.
     */
    public function reasonLabel(string $reason, string $productName): string
    {
        return match ($reason) {
            self::REASON_MISSING_BASIS => __('Falta elegir cómo se costea :product (granel o una presentación).', ['product' => $productName]),
            self::REASON_MISSING_DENSITY => __(':product no tiene peso por galón: ni el de referencia ni una OP completada.', ['product' => $productName]),
            self::REASON_MISSING_SOURCE_COST => __(':product todavía no tiene costo: le falta su fórmula de referencia costeada.', ['product' => $productName]),
            self::REASON_INACTIVE_PRESENTATION => __('La presentación con la que se costea :product está inactiva.', ['product' => $productName]),
            default => $reason,
        };
    }

    /**
     * @param  array<int, array{density: string, order_number: string}>  $lastLots
     * @return array{cost_per_kg: string|null, bulk_per_kg: string|null, packaging_per_kg: string|null, density: string|null, density_source: string|null, density_order_number: string|null, reason: string|null}
     */
    private function costFor(FormulaDetail $line, ?Product $product, ?ProductVariant $variant, array $lastLots): array
    {
        $lastLot = $lastLots[(int) $line->product_id] ?? null;
        $density = $product?->reference_density_kg_per_gallon !== null
            ? (string) $product->reference_density_kg_per_gallon
            : $lastLot['density'] ?? null;

        $result = [
            'cost_per_kg' => null,
            'bulk_per_kg' => null,
            'packaging_per_kg' => null,
            'density' => $density,
            'density_source' => $product?->reference_density_kg_per_gallon !== null ? 'reference' : ($lastLot !== null ? 'last_lot' : null),
            'density_order_number' => $product?->reference_density_kg_per_gallon === null ? ($lastLot['order_number'] ?? null) : null,
            'reason' => null,
        ];

        $reason = match (true) {
            $line->costing_basis === null => self::REASON_MISSING_BASIS,
            $product === null || $product->current_cost === null => self::REASON_MISSING_SOURCE_COST,
            $density === null || ! $this->calculator->isPositive($density) => self::REASON_MISSING_DENSITY,
            default => null,
        };

        if ($reason !== null) {
            return [...$result, 'reason' => $reason];
        }

        $cif = $product->cif_percentage !== null ? (string) $product->cif_percentage : null;
        $bulkPerKg = $this->calculator->div(
            $this->productionCostCalculator->transferCost((string) $product->current_cost, $cif),
            $density,
            self::SCALE,
        );

        if ($line->costing_basis === FormulaLineCostingBasis::Bulk) {
            $bulkPerKg = $this->calculator->round($bulkPerKg, 4);

            return [...$result, 'cost_per_kg' => $bulkPerKg, 'bulk_per_kg' => $bulkPerKg, 'packaging_per_kg' => '0.0000'];
        }

        if ($variant === null || ! $variant->is_active) {
            return [...$result, 'reason' => self::REASON_INACTIVE_PRESENTATION];
        }

        if ($variant->current_cost === null) {
            return [...$result, 'reason' => self::REASON_MISSING_SOURCE_COST];
        }

        // Sin valor de presentación, 1 galón: la misma regla que `VariantPricingService`.
        $kilosPerUnit = $this->calculator->mul((string) ($variant->presentation_value ?? 1), $density, self::SCALE);

        if (! $this->calculator->isPositive($kilosPerUnit)) {
            return [...$result, 'reason' => self::REASON_MISSING_DENSITY];
        }

        $costPerKg = $this->calculator->round($this->calculator->div(
            $this->productionCostCalculator->transferCost((string) $variant->current_cost, $cif),
            $kilosPerUnit,
            self::SCALE,
        ), 4);
        $bulkPerKg = $this->calculator->round($bulkPerKg, 4);

        return [
            ...$result,
            'cost_per_kg' => $costPerKg,
            'bulk_per_kg' => $bulkPerKg,
            'packaging_per_kg' => $this->calculator->sub($costPerKg, $bulkPerKg, 4),
        ];
    }

    /**
     * La densidad de la última OP completada de cada producto (sin peso de referencia), con su número: una consulta por
     * producto que trae **una** fila. Una fórmula lleva pocos productos; traer todas sus OP para quedarse con la primera
     * crecería con el tiempo.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{density: string, order_number: string}>
     */
    private function lastLotDensities(array $productIds): array
    {
        $densities = [];

        foreach ($productIds as $productId) {
            $order = ProductionOrder::query()
                ->where('product_id', $productId)
                ->where('status', ProductionOrderStatus::Completed)
                ->whereNotNull('density_kg_per_gallon')
                ->orderByDesc('completion_date')
                ->orderByDesc('id')
                ->first(['id', 'order_number', 'density_kg_per_gallon']);

            if ($order !== null) {
                $densities[(int) $productId] = [
                    'density' => (string) $order->density_kg_per_gallon,
                    'order_number' => (string) $order->order_number,
                ];
            }
        }

        return $densities;
    }
}
