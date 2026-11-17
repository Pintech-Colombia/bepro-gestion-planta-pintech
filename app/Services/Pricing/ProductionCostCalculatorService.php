<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\ProductionOrder;
use App\Services\DecimalCalculator;
use App\Services\Production\ProductionOrderCompletionLines;

class ProductionCostCalculatorService
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductionOrderCompletionLines $completionLines,
    ) {}

    public function applyCifToCost(string $cost, string $cifPercentage): string
    {
        $cifRatio = $this->calculator->div($cifPercentage, '100', 4);
        $cifFactor = $this->calculator->add('1', $cifRatio, 4);

        return $this->calculator->mul($cost, $cifFactor, 4);
    }

    /**
     * Costo de traslado: el costo con el CIF del producto. Sin CIF (nulo o 0) es el mismo costo. Es la regla del saldo
     * (costo por galón) y del lote de PT (costo por unidad) al completar la OP, y la de su vista previa.
     */
    public function transferCost(string $cost, ?string $cifPercentage): string
    {
        if ($cifPercentage === null || $this->calculator->cmp($cifPercentage, '0', 4) <= 0) {
            return $cost;
        }

        return $this->applyCifToCost($cost, $cifPercentage);
    }

    /**
     * @param  array<int, array{id:int,actual_units:float|int}>  $packagingData
     * @return array{distributedCosts: array<int, string>, bulkCostPerUnit: string|null}
     */
    public function calculateDistributedBulkCosts(ProductionOrder $order, array $packagingData, string|float $totalBulkCost, ?string $remnantGallons = null): array
    {
        $totalBulkCostStr = (string) $totalBulkCost;

        if ($this->calculator->isZero($totalBulkCostStr)) {
            return ['distributedCosts' => [], 'bulkCostPerUnit' => null];
        }

        $packagingDataByPlanId = [];

        foreach ($packagingData as $packData) {
            $packagingDataByPlanId[(int) $packData['id']] = $packData;
        }

        $order->loadMissing('packagingPlans.productVariant');
        $plans = $order->packagingPlans;

        // El costo se reparte entre lo que de verdad salió: la misma cuenta que el rendimiento real que se guarda.
        $totalEquivalentYield = $this->completionLines->realYield($order, array_values($packagingDataByPlanId), $remnantGallons);

        if ($this->calculator->isZero($totalEquivalentYield)) {
            return ['distributedCosts' => [], 'bulkCostPerUnit' => null];
        }

        $bulkCostPerEquivalentUnit = $this->calculator->div($totalBulkCostStr, $totalEquivalentYield, 4);
        $distributedCosts = [];

        foreach ($plans as $plan) {
            if (! isset($packagingDataByPlanId[$plan->id])) {
                continue;
            }

            $variant = $plan->productVariant;
            if ($variant === null) {
                continue;
            }

            $actualUnits = $this->calculator->normalize($packagingDataByPlanId[$plan->id]['actual_units'] ?? 0);
            if ($this->calculator->isZero($actualUnits)) {
                continue;
            }

            $presentationValue = (string) ($variant->presentation_value ?? 1);
            $distributedCost = $this->calculator->mul($bulkCostPerEquivalentUnit, $presentationValue, 4);
            $distributedCosts[(int) $variant->id] = $distributedCost;
        }

        return [
            'distributedCosts' => $distributedCosts,
            'bulkCostPerUnit' => $bulkCostPerEquivalentUnit,
        ];
    }
}
