<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Models\ProductionOrder;
use App\Models\RemnantConsumption;
use App\Models\User;
use App\Services\Production\ProductionOrderLock;
use App\Services\Production\ProductLinePlanner;
use Illuminate\Validation\ValidationException;

class RemoveRemnantConsumptionAction
{
    public function __construct(
        private readonly ProductionOrderLock $orderLock,
        private readonly ProductLinePlanner $planner,
    ) {}

    /**
     * Quita una línea planeada de saldo de una OP abierta. El saldo no se toca: la línea solo reservaba sus kilos. Una
     * línea ya aplicada (descontada del saldo) no se quita. Corregir la cantidad es quitar y volver a agregar. Si era la
     * última fuente de una línea agregada de «Productos a usar», la línea también se va.
     */
    public function execute(ProductionOrder $order, RemnantConsumption $consumption, User $user): void
    {
        // Con la OP bloqueada: una línea quitada mientras se completa la orden dejaría un costo sin su saldo.
        $this->orderLock->write(
            $order,
            $user,
            'planRemnant',
            "No se puede quitar un saldo de una orden en estado ':status'.",
            function (ProductionOrder $lockedOrder) use ($consumption): void {
                $lockedLine = RemnantConsumption::query()
                    ->where('target_order_id', $lockedOrder->id)
                    ->find($consumption->id);

                if ($lockedLine === null) {
                    abort(404);
                }

                if ($lockedLine->isApplied()) {
                    throw ValidationException::withMessages([
                        'remnant_consumption' => __('Este saldo ya se descontó del inventario y no se puede quitar.'),
                    ]);
                }

                $productLineId = $lockedLine->production_order_product_line_id;
                $lockedLine->delete();

                $this->planner->pruneIfEmpty($productLineId);
            },
        );
    }
}
