<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Models\FinishedProductBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\User;
use App\Services\DecimalCalculator;
use App\Services\Production\ProductionOrderLock;
use Illuminate\Validation\ValidationException;

class UpdateBatchConsumptionUnitsAction
{
    public function __construct(
        private readonly ProductionOrderLock $orderLock,
        private readonly PlanBatchConsumptionAction $planBatchConsumption,
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Cambia las unidades de un lote planeado en una OP abierta: las que se sacaron de bodega. Las nuevas no pueden pasar
     * de lo libre del lote más las que la fuente ya tenía. Una fuente ya aplicada no cambia.
     */
    public function execute(ProductionOrder $order, ProductionOrderBatchConsumption $source, string $units, User $user): ProductionOrderBatchConsumption
    {
        return $this->orderLock->write(
            $order,
            $user,
            'planRemnant',
            "No se pueden cambiar los lotes de una orden en estado ':status'.",
            function (ProductionOrder $lockedOrder) use ($source, $units): ProductionOrderBatchConsumption {
                $lockedSource = ProductionOrderBatchConsumption::query()
                    ->where('production_order_id', $lockedOrder->id)
                    ->find($source->id);

                if ($lockedSource === null) {
                    abort(404);
                }

                if ($lockedSource->isApplied()) {
                    throw ValidationException::withMessages([
                        'units' => __('Este lote ya salió del inventario y no se puede cambiar.'),
                    ]);
                }

                $lockedBatch = FinishedProductBatch::query()->lockForUpdate()->findOrFail($lockedSource->finished_product_batch_id);

                // Lo libre sin las unidades de esta orden: la fuente no compite consigo misma. Si la orden usa el mismo lote
                // en otra línea, esas unidades también salen, así que cuentan junto con las nuevas.
                $ownOtherUnits = (string) ProductionOrderBatchConsumption::query()
                    ->planned()
                    ->where('production_order_id', $lockedOrder->id)
                    ->where('finished_product_batch_id', $lockedBatch->id)
                    ->where('warehouse_id', $lockedSource->warehouse_id)
                    ->whereKeyNot($lockedSource->id)
                    ->sum('units');

                $this->planBatchConsumption->ensureCovered(
                    $lockedBatch,
                    (int) $lockedSource->warehouse_id,
                    $this->calculator->add($units, $ownOtherUnits, 0),
                    (int) $lockedOrder->id,
                );

                $lockedSource->update(['units' => $units]);

                return $lockedSource;
            },
        );
    }
}
