<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\RemnantConsumption;
use App\Services\DecimalCalculator;

/**
 * Por línea de producto de una OP, lo que cubren sus fuentes y lo que cuestan: el renglón del producto en la tabla de
 * consumo. Una sola cuenta para el detalle de la orden y la vista previa de costos.
 *
 * - Cubierto = los kilos de sus saldos + unidades × kilos por unidad de sus lotes (lo que traen, no lo gastado). Un lote
 *   sin peso por galón no suma y `has_unknown_kg` lo avisa: entonces no se sabe cuánto falta ni el costo por kilo.
 * - Costo = el de sus saldos (`RemnantDrawdown::lineCost`) + el de sus lotes (`BatchSourceDrawdown::lineCost`): el real
 *   si ya se aplicó, el estimado si no. No depende de lo gastado: sale de lo que se saca de bodega. Si una fuente no se
 *   puede estimar, el costo de la línea es null (no se muestra un total incompleto).
 * - Costo por kilo = costo ÷ lo que salió de las fuentes (cubierto − lo que quedó en los envases abiertos): el costo ya
 *   descuenta lo que volvió al saldo, así que los kilos también.
 */
class ProductLineCosts
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly RemnantDrawdown $remnantDrawdown,
        private readonly BatchSourceDrawdown $batchSources,
    ) {}

    /**
     * @param  bool  $withCost  sin «Ver costos», o con la orden cancelada (las fuentes no se usaron), sin costo
     * @param  array<int, array{leftover_kg: string|null}>|null  $batchUsage  lo que quedó escrito por fuente de lote (vista previa)
     * @return array<int, array{covered_kg: string, used_kg: string, has_unknown_kg: bool, cost: string|null, unit_cost: string|null}> por id de línea
     */
    public function forOrder(ProductionOrder $order, bool $withCost, ?array $batchUsage = null): array
    {
        $order->loadMissing([
            'productLines',
            'remnantConsumptions.remnant',
            'batchConsumptions.batch.productVariant',
            'batchConsumptions.batch.productionOrder',
        ]);

        $rows = [];

        foreach ($order->productLines as $line) {
            $remnants = $order->remnantConsumptions
                ->filter(fn (RemnantConsumption $consumption): bool => (int) $consumption->production_order_product_line_id === (int) $line->id);
            $lots = $order->batchConsumptions
                ->filter(fn (ProductionOrderBatchConsumption $source): bool => (int) $source->production_order_product_line_id === (int) $line->id);

            $kilos = $remnants->map(fn (RemnantConsumption $consumption): string => (string) $consumption->quantity_kg)->all();
            $leftovers = [];
            $costs = [];
            $hasUnknownKg = false;
            $costKnown = true;

            foreach ($remnants as $consumption) {
                $cost = $withCost ? $this->remnantDrawdown->lineCost($consumption) : null;
                $costKnown = $costKnown && $cost !== null;
                $costs[] = (string) $cost;
            }

            foreach ($lots as $source) {
                $kgPerUnit = $source->isApplied() && $source->kg_per_unit !== null
                    ? (string) $source->kg_per_unit
                    : $source->batch->kgPerUnit();

                if ($kgPerUnit === null) {
                    $hasUnknownKg = true;
                } else {
                    $kilos[] = $this->calculator->mul((string) $source->units, $kgPerUnit, 4);
                    $leftovers[] = (string) ($this->batchSources->leftoverKg($source, $batchUsage[$source->id] ?? null) ?? '0');
                }

                $cost = $withCost ? $this->batchSources->lineCost($source, $batchUsage[$source->id] ?? null) : null;
                $costKnown = $costKnown && $cost !== null;
                $costs[] = (string) $cost;
            }

            $covered = $this->calculator->sum($kilos, 4);
            $used = $this->calculator->sub($covered, $this->calculator->sum($leftovers, 4), 4);
            $cost = $withCost && $costKnown && $costs !== [] ? $this->calculator->sum($costs, 4) : null;

            $rows[(int) $line->id] = [
                'covered_kg' => $covered,
                // Lo que salió de las fuentes: cubierto − lo que volvió al saldo. Es el divisor del costo por kilo.
                'used_kg' => $used,
                'has_unknown_kg' => $hasUnknownKg,
                'cost' => $cost,
                // Con kilos desconocidos el costo cuenta el lote y los kilos no: el cociente saldría inflado.
                'unit_cost' => $cost !== null && ! $hasUnknownKg && $this->calculator->isPositive($used)
                    ? $this->calculator->div($cost, $used, 4)
                    : null,
            ];
        }

        return $rows;
    }
}
