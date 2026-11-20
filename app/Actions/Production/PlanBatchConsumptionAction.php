<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Models\FinishedProductBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\User;
use App\Services\AvailableBatchesService;
use App\Services\DecimalCalculator;
use App\Services\Production\ProductionOrderLock;
use App\Services\Production\ProductLinePlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanBatchConsumptionAction
{
    public const ERROR_KEY = 'finished_product_batch_id';

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductionOrderLock $orderLock,
        private readonly AvailableBatchesService $availableBatches,
        private readonly ProductLinePlanner $planner,
    ) {}

    /**
     * Agrega a una OP abierta un lote de PT como fuente de su línea de producto, en unidades enteras. El lote no se toca:
     * sus unidades quedan comprometidas y salen al completar la orden. Solo de la bodega de la orden y sin pasar de lo
     * libre del lote. Un lote de un producto que la orden no tiene crea una línea «agregada».
     */
    public function execute(
        ProductionOrder $order,
        FinishedProductBatch $batch,
        string $units,
        User $user,
        ?int $lineId = null,
    ): ProductionOrderBatchConsumption {
        return DB::transaction(function () use ($order, $batch, $units, $user, $lineId): ProductionOrderBatchConsumption {
            // La OP primero y después el lote (el orden de completar), una sola OP por transacción: nadie espera en el
            // orden contrario.
            $lockedOrder = $this->orderLock->lockFor(
                $order,
                $user,
                'planRemnant',
                "No se puede agregar un lote a una orden en estado ':status'.",
            );

            // Con el lote bloqueado, otra orden que lo agrega o una salida a la vez esperan y después ven esta fuente.
            $lockedBatch = FinishedProductBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $warehouseId = (int) $lockedOrder->warehouse_id;

            $line = $this->planner->lineFor($lockedOrder, (int) $lockedBatch->product_id, $lineId, self::ERROR_KEY);

            if ($line->batchConsumptions()->where('finished_product_batch_id', $lockedBatch->id)->exists()) {
                throw ValidationException::withMessages([
                    self::ERROR_KEY => __('Ese lote ya está en la línea: cambia sus unidades.'),
                ]);
            }

            $this->ensureCovered($lockedBatch, $warehouseId, $units);

            return ProductionOrderBatchConsumption::create([
                'production_order_id' => $lockedOrder->id,
                'production_order_product_line_id' => $line->id,
                'finished_product_batch_id' => $lockedBatch->id,
                'warehouse_id' => $warehouseId,
                'units' => $units,
                'created_by' => $user->id,
            ]);
        }, attempts: 3);
    }

    /**
     * El lote tiene que estar en la bodega de la orden (lo que está en otra se traslada antes) y tener esas unidades
     * libres. Lo comparten agregar y cambiar las unidades.
     */
    public function ensureCovered(FinishedProductBatch $lockedBatch, int $warehouseId, string $units, ?int $exceptOrderId = null): void
    {
        if (! $this->calculator->isPositive($units)) {
            throw ValidationException::withMessages([
                'units' => __('Las unidades a usar deben ser mayores a cero.'),
            ]);
        }

        if (! $lockedBatch->stocks()->forWarehouse($warehouseId)->available()->exists()) {
            throw ValidationException::withMessages([
                self::ERROR_KEY => __('El lote tiene que estar en la bodega de la orden: si está en otra, trasládalo antes.'),
            ]);
        }

        $this->availableBatches->ensureFree((int) $lockedBatch->id, $warehouseId, $units, $exceptOrderId, 'units');
    }
}
