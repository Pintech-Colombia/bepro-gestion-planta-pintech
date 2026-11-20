<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\FinishedInventoryMovementReason;
use App\Enums\NumberType;
use App\Enums\ProductionOrderProductLineOrigin;
use App\Models\FinishedProductBatch;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionOrderProductLine;
use App\Models\ProductionRemnant;
use App\Services\DecimalCalculator;
use App\Services\FinishedInventory\EstimatedTransferCostBackfill;
use App\Services\FinishedInventory\FinishedInventoryMovementService;
use App\Services\Pricing\ProductionCostCalculatorService;
use App\Support\NumberFormat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * La regla de las fuentes de lote de una OP, en un solo lugar: lo que se registra al enviar a revisión o completar, el
 * costo (estimado en la vista previa, real al completar) y la salida del inventario.
 *
 * - Salen **las unidades de la fuente** (las que se sacaron de bodega), no unas calculadas desde los kilos: el peso por
 *   unidad es teórico (galones × peso por galón) y un llenado de más pediría una unidad que no se abrió.
 * - Lo que quedó en el envase abierto se **pesa**: esos kilos vuelven al saldo de la OP que fabricó el lote, a su precio
 *   de granel (el granel por galón con el CIF de esa OP, la misma cuenta de su sobrante, sin envase). Si esa OP no tiene
 *   saldo, nace en la bodega de la OP que consume.
 * - Costo de la fuente = unidades × costo de traslado − valor de lo que volvió al saldo: la OP que abrió el envase lo paga
 *   completo (los envases vacíos se cuentan perdidos).
 */
class BatchSourceDrawdown
{
    /** La clave de los errores: la lista de lotes que envía la pantalla. */
    public const ERROR_KEY = 'batch_consumptions';

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductionCostCalculatorService $costCalculator,
        private readonly EstimatedTransferCostBackfill $transferCostBackfill,
        private readonly FinishedInventoryMovementService $finishedInventory,
        private readonly RemnantLedger $remnantLedger,
        private readonly ProductionOrderCompletionLines $completionLines,
        private readonly RemnantDrawdown $remnantDrawdown,
    ) {}

    /**
     * Al enviar a revisión o completar, con la OP bloqueada y la lista ya comparada (`ensureMatches`): guarda en cada
     * fuente de lote los kilos usados y lo que quedó, pesado (nulo si no quedó nada). Lo que quedó no pasa de una unidad.
     * 422 si una línea de la fórmula no tiene ninguna fuente: lo normal es un olvido y la orden quedaría sin ese costo.
     *
     * @param  list<array<string, mixed>>  $rows  `batch_consumptions` validado: id, used_kg, has_leftover, leftover_kg
     */
    public function recordUsage(ProductionOrder $lockedOrder, array $rows): void
    {
        $sources = $lockedOrder->batchConsumptions()
            ->with(['batch.productVariant', 'batch.productionOrder'])
            ->get()
            ->keyBy('id');
        $errors = [];

        foreach (array_values($rows) as $index => $row) {
            $source = $sources->get((int) $row['id']);

            if ($source === null || $source->isApplied()) {
                continue;
            }

            $leftoverKg = filter_var($row['has_leftover'] ?? false, FILTER_VALIDATE_BOOLEAN) && isset($row['leftover_kg'])
                ? $this->calculator->round((string) $row['leftover_kg'], 4)
                : null;

            if ($leftoverKg !== null) {
                $kgPerUnit = $source->batch->kgPerUnit();

                if ($kgPerUnit === null) {
                    $errors[self::ERROR_KEY.".{$index}.leftover_kg"] = __('El lote :lot no tiene peso por galón: no se puede saber cuánto cabe en una unidad.', [
                        'lot' => $source->batch->productionOrder?->lot_number,
                    ]);

                    continue;
                }

                if ($this->calculator->cmp($leftoverKg, $kgPerUnit, 4) > 0) {
                    $errors[self::ERROR_KEY.".{$index}.leftover_kg"] = __('Lo que quedó no puede pasar de una unidad del lote :lot (:kg kg).', [
                        'lot' => $source->batch->productionOrder?->lot_number,
                        'kg' => NumberFormat::quantity($kgPerUnit),
                    ]);

                    continue;
                }
            }

            $source->update([
                'used_kg' => $this->calculator->round((string) $row['used_kg'], 4),
                'leftover_kg' => $leftoverKg,
            ]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->ensureFormulaLinesHaveSources($lockedOrder);
    }

    /**
     * 422 si alguna línea de la fórmula no tiene ni un saldo ni un lote.
     */
    public function ensureFormulaLinesHaveSources(ProductionOrder $order): void
    {
        $missing = ProductionOrderProductLine::query()
            ->where('production_order_id', $order->id)
            ->where('origin', ProductionOrderProductLineOrigin::Formula->value)
            ->whereDoesntHave('remnantConsumptions')
            ->whereDoesntHave('batchConsumptions')
            ->with('product:id,name')
            ->orderBy('id')
            ->get();

        if ($missing->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'product_lines' => __('Falta de dónde sale :products: agrega un saldo o un lote a cada producto de la fórmula.', [
                'products' => $missing->map(fn (ProductionOrderProductLine $line): string => (string) $line->product?->name)->implode(', '),
            ]),
        ]);
    }

    /**
     * El costo de una fuente: el real si ya se aplicó; si no, el estimado con lo que tiene ahora (el costo de traslado del
     * lote, o el estimado si no lo tiene, y lo que quedó si ya se registró). Nulo si no se puede estimar. Lo usan la vista
     * previa de costos y el resumen de la orden.
     */
    public function lineCost(ProductionOrderBatchConsumption $source): ?string
    {
        if ($source->isApplied()) {
            return $source->consumed_cost !== null ? (string) $source->consumed_cost : null;
        }

        $batch = $source->batch;
        $transferUnitCost = $batch->transfer_unit_cost !== null
            ? (string) $batch->transfer_unit_cost
            : $this->transferCostBackfill->estimateFor((int) $batch->id);

        if ($transferUnitCost === null) {
            return null;
        }

        $total = $this->calculator->mul((string) $source->units, $transferUnitCost, 4);

        if ($source->leftover_kg === null) {
            return $total;
        }

        $originOrder = $batch->productionOrder;
        $density = $originOrder?->density_kg_per_gallon;

        if ($originOrder === null || $density === null || ! $this->calculator->isPositive((string) $density)) {
            return $total;
        }

        $originRemnant = ProductionRemnant::query()->where('source_order_id', $originOrder->id)->first();

        return $this->calculator->sub(
            $total,
            $this->leftoverValue((string) $source->leftover_kg, $this->originCostPerGallon($originOrder, $originRemnant), (string) $density),
            4,
        );
    }

    /**
     * El granel por galón con CIF de la OP que fabricó el lote: el precio de su saldo. Si ya tiene saldo, el que el saldo
     * guardó al nacer (el mismo con que lo paga quien lo use); si no, la misma cuenta de su sobrante: el costo por galón de
     * la OP (`production_costs.unit_cost`) con el CIF que guardó al completarse.
     */
    public function originCostPerGallon(ProductionOrder $originOrder, ?ProductionRemnant $originRemnant): string
    {
        if ($originRemnant?->cost_per_gallon !== null) {
            return (string) $originRemnant->cost_per_gallon;
        }

        $bulkPerGallon = ProductionCost::query()->where('production_order_id', $originOrder->id)->value('unit_cost');

        return $this->costCalculator->transferCost(
            (string) ($bulkPerGallon ?? '0'),
            $originOrder->cif_percentage !== null ? (string) $originOrder->cif_percentage : null,
        );
    }

    /**
     * El valor a precio de granel de los kilos que volvieron al saldo: kilos × costo por galón ÷ peso por galón. Se
     * multiplica antes de dividir: pasar primero a galones con 4 decimales movería el resultado en centavos.
     */
    public function leftoverValue(string $leftoverKg, string $costPerGallon, string $density): string
    {
        return $this->calculator->round(
            $this->calculator->div($this->calculator->mul($leftoverKg, $costPerGallon, 10), $density, 10),
            4,
        );
    }

    /**
     * Aplica una fuente al completar la orden: salen sus unidades del lote («Transformación», con la orden que consume),
     * lo que quedó vuelve al saldo de la OP de origen y la fuente guarda los kilos por unidad, el costo y la fecha.
     * Quien llama ya bloqueó la orden, los saldos (`$lockedOriginRemnant`, nulo si la OP de origen no tiene) y el lote,
     * en ese orden. Devuelve el saldo de origen si quedó producto (el que había o el que nació).
     */
    public function apply(
        ProductionOrderBatchConsumption $source,
        FinishedProductBatch $lockedBatch,
        ?ProductionRemnant $lockedOriginRemnant,
        ProductionOrder $lockedOrder,
        int $userId,
        string $movementDate,
    ): ?ProductionRemnant {
        $lockedBatch->loadMissing(['productVariant', 'productionOrder']);
        $originOrder = $lockedBatch->productionOrder;
        $kgPerUnit = $lockedBatch->kgPerUnit();

        $transferUnitCost = $lockedBatch->transfer_unit_cost !== null
            ? (string) $lockedBatch->transfer_unit_cost
            : $this->transferCostBackfill->fill((int) $lockedBatch->id);

        if ($transferUnitCost === null) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => __('El lote :lot no tiene costo: no se puede trasladar a esta orden.', [
                    'lot' => $originOrder?->lot_number,
                ]),
            ]);
        }

        $cost = $this->calculator->mul((string) $source->units, $transferUnitCost, 4);
        $originRemnant = null;

        if ($source->leftover_kg !== null) {
            [$originRemnant, $leftoverValue] = $this->returnLeftover($source, $lockedBatch, $originOrder, $lockedOriginRemnant, $lockedOrder, $userId);
            $cost = $this->calculator->sub($cost, $leftoverValue, 4);
        }

        NumberType::Money->ensureFits(
            $cost,
            self::ERROR_KEY,
            __('El costo del lote :lot no cabe en el registro. Revisa las unidades.', ['lot' => $originOrder?->lot_number]),
        );

        // Salen las unidades de la fuente, sin tomar las comprometidas por otras órdenes (lo revisa la salida).
        $this->finishedInventory->registerExit(
            batchId: (int) $lockedBatch->id,
            warehouseId: (int) $source->warehouse_id,
            quantity: (string) $source->units,
            reason: FinishedInventoryMovementReason::Transformation,
            userId: $userId,
            notes: "Consumo en OP #{$lockedOrder->order_number}",
            movementDate: $movementDate,
            productionOrderId: (int) $lockedOrder->id,
        );

        $source->update([
            'kg_per_unit' => $kgPerUnit,
            'consumed_cost' => $cost,
            'applied_at' => now(),
        ]);

        return $originRemnant;
    }

    /**
     * Lo que quedó en el envase abierto vuelve al saldo de la OP de origen. 422 si ese saldo está en otra bodega: no se
     * pueden juntar.
     *
     * @return array{0: ProductionRemnant, 1: string} el saldo y el valor de lo que volvió
     */
    private function returnLeftover(
        ProductionOrderBatchConsumption $source,
        FinishedProductBatch $lockedBatch,
        ?ProductionOrder $originOrder,
        ?ProductionRemnant $lockedOriginRemnant,
        ProductionOrder $lockedOrder,
        int $userId,
    ): array {
        $leftoverKg = (string) $source->leftover_kg;
        $lot = $originOrder?->lot_number;
        $density = $originOrder?->density_kg_per_gallon;

        if ($originOrder === null || $density === null || ! $this->calculator->isPositive((string) $density)) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => __('El lote :lot no tiene peso por galón: no se puede devolver al saldo lo que quedó.', ['lot' => $lot]),
            ]);
        }

        if ($lockedOriginRemnant !== null && (int) $lockedOriginRemnant->warehouse_id !== (int) $lockedOrder->warehouse_id) {
            $lockedOriginRemnant->loadMissing('warehouse:id,name');

            throw ValidationException::withMessages([
                self::ERROR_KEY => __('Lo que quedó del lote :lot vuelve al saldo de :origin, y ese saldo está en otra bodega (:warehouse): no se pueden juntar, así que la orden no se puede completar.', [
                    'lot' => $lot,
                    'origin' => $originOrder->order_number,
                    'warehouse' => $lockedOriginRemnant->warehouse?->name,
                ]),
            ]);
        }

        // Los galones salen de los kilos, con la regla del saldo: los de lo que quedó si el saldo nace, los de todo lo que
        // tendrá si ya existía.
        $gallons = $lockedOriginRemnant === null
            ? $this->completionLines->remnantGallons($leftoverKg, $density)
            : $this->remnantDrawdown->gallonsFor($lockedOriginRemnant, $this->calculator->add((string) $lockedOriginRemnant->available_quantity_kg, $leftoverKg, 4));

        if ($gallons === null || ! $this->calculator->isPositive($gallons)) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => __('Lo que quedó del lote :lot es demasiado poco para registrarlo como saldo.', ['lot' => $lot]),
            ]);
        }

        $costPerGallon = $this->originCostPerGallon($originOrder, $lockedOriginRemnant);

        try {
            $remnant = $this->remnantLedger->returnFromOpenedUnit(
                $lockedOriginRemnant,
                [
                    'source_order_id' => $originOrder->id,
                    'product_id' => $lockedBatch->product_id,
                    'warehouse_id' => $lockedOrder->warehouse_id,
                    'density_kg_per_gallon' => $density,
                    'cost_per_gallon' => $costPerGallon,
                    'created_by' => $userId,
                ],
                $leftoverKg,
                $gallons,
                $source,
                $userId,
            );
        } catch (UniqueConstraintViolationException) {
            // Otra orden creó el saldo de la OP de origen al mismo tiempo: un saldo por OP. Al reintentar ya lo encuentra.
            throw ValidationException::withMessages([
                self::ERROR_KEY => __('Otra orden acaba de crear el saldo de :origin. Vuelve a confirmar.', ['origin' => $originOrder->order_number]),
            ]);
        }

        return [$remnant, $this->leftoverValue($leftoverKg, $costPerGallon, (string) $density)];
    }
}
