<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\User;
use App\Services\Production\ProductionOrderLock;
use App\Services\Production\ProductLinePlanner;
use Illuminate\Validation\ValidationException;

class RemoveBatchConsumptionAction
{
    public function __construct(
        private readonly ProductionOrderLock $orderLock,
        private readonly ProductLinePlanner $planner,
    ) {}

    /**
     * Quita un lote planeado de una OP abierta: sus unidades dejan de estar comprometidas. Un lote ya aplicado (que salió
     * del inventario) no se quita. Si era la última fuente de una línea agregada, la línea también se va.
     */
    public function execute(ProductionOrder $order, ProductionOrderBatchConsumption $source, User $user): void
    {
        // Con la OP bloqueada: un lote quitado mientras se completa la orden dejaría un costo sin su salida.
        $this->orderLock->write(
            $order,
            $user,
            'planRemnant',
            "No se puede quitar un lote de una orden en estado ':status'.",
            function (ProductionOrder $lockedOrder) use ($source): void {
                $lockedSource = ProductionOrderBatchConsumption::query()
                    ->where('production_order_id', $lockedOrder->id)
                    ->find($source->id);

                if ($lockedSource === null) {
                    abort(404);
                }

                if ($lockedSource->isApplied()) {
                    throw ValidationException::withMessages([
                        'batch_consumption' => __('Este lote ya salió del inventario y no se puede quitar.'),
                    ]);
                }

                $lineId = (int) $lockedSource->production_order_product_line_id;
                $lockedSource->delete();

                $this->planner->pruneIfEmpty($lineId);
            },
        );
    }
}
