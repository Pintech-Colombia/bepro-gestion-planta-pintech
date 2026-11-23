<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderProductLine;
use App\Services\AvailableBatchesService;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;

/**
 * Lo que falta de cada producto terminado que la OP lleva como ingrediente, al crearla: el hermano de
 * `FifoStockAllocatorService::stockShortages` para las líneas de producto. Avisa, no impide crear (la orden nace sin
 * fuentes; lo que no deja enviar ni completar es una línea de la fórmula sin ninguna).
 *
 * Por producto, en kilos y en la bodega de la orden: requerido = lo planeado de sus líneas de la fórmula; disponible =
 * los kilos libres de sus saldos (sin lo planeado en órdenes abiertas) + las unidades libres de sus lotes (sin lo
 * comprometido) × kilos por unidad. Un lote sin peso por galón no suma kilos: `has_unknown_kg` lo avisa. Las líneas
 * agregadas no cuentan: nacen sin plan.
 *
 * @phpstan-type ProductShortage array{
 *   product_id: int,
 *   name: string,
 *   unit: string,
 *   required: string,
 *   available: string,
 *   remnants_kg: string,
 *   lots_kg: string,
 *   has_unknown_kg: bool,
 *   missing: string
 * }
 */
class ProductLineShortages
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly AvailableBatchesService $availableBatches,
    ) {}

    /**
     * @return list<ProductShortage>
     */
    public function forOrder(ProductionOrder $order): array
    {
        $order->load('productLines.product:id,name');

        /** @var array<int, array{name: string, required: string}> $required */
        $required = [];

        foreach ($order->productLines as $line) {
            /** @var ProductionOrderProductLine $line */
            if ($line->planned_kg === null || ! $this->calculator->isPositive((string) $line->planned_kg)) {
                continue;
            }

            $productId = (int) $line->product_id;
            $required[$productId] ??= ['name' => (string) $line->product?->name, 'required' => '0'];
            $required[$productId]['required'] = $this->calculator->add($required[$productId]['required'], (string) $line->planned_kg, 4);
        }

        if ($required === []) {
            return [];
        }

        $remnantsKg = [];
        foreach ($this->availableRemnants->forOrder($order) as $remnant) {
            $productId = $remnant['product_id'];
            $remnantsKg[$productId] = $this->calculator->add($remnantsKg[$productId] ?? '0', $remnant['available_quantity_kg'], 4);
        }

        $lotsKg = [];
        $unknownKg = [];
        foreach ($this->availableBatches->forOrder($order, includeCosts: false) as $batch) {
            $productId = $batch['product_id'];

            if ($batch['kg_per_unit'] === null) {
                $unknownKg[$productId] = true;

                continue;
            }

            $lotsKg[$productId] = $this->calculator->add(
                $lotsKg[$productId] ?? '0',
                $this->calculator->mul($batch['free_units'], $batch['kg_per_unit'], 4),
                4,
            );
        }

        $shortages = [];

        foreach ($required as $productId => $product) {
            $remnants = $remnantsKg[$productId] ?? '0.0000';
            $lots = $lotsKg[$productId] ?? '0.0000';
            $available = $this->calculator->add($remnants, $lots, 4);

            if ($this->calculator->cmp($available, $product['required'], 4) >= 0) {
                continue;
            }

            $shortages[] = [
                'product_id' => $productId,
                'name' => $product['name'],
                'unit' => 'kg',
                'required' => $product['required'],
                'available' => $available,
                'remnants_kg' => $remnants,
                'lots_kg' => $lots,
                'has_unknown_kg' => $unknownKg[$productId] ?? false,
                'missing' => $this->calculator->sub($product['required'], $available, 4),
            ];
        }

        return $shortages;
    }
}
