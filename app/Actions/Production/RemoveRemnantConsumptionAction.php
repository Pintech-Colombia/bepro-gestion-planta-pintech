<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Models\ProductionOrder;
use App\Models\RemnantConsumption;
use App\Models\User;
use App\Services\Production\ProductionOrderLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveRemnantConsumptionAction
{
    public function __construct(
        private readonly ProductionOrderLock $orderLock,
    ) {}

    /**
     * Quita una línea planeada de saldo de una OP abierta. El saldo no se toca: la línea solo reservaba sus kilos. Una
     * línea ya aplicada (descontada del saldo) no se quita. Corregir la cantidad es quitar y volver a agregar.
     */
    public function execute(ProductionOrder $order, RemnantConsumption $consumption, User $user): void
    {
        DB::transaction(function () use ($order, $consumption, $user): void {
            // Con la OP bloqueada: una línea quitada mientras se completa la orden dejaría un costo sin su saldo.
            $lockedOrder = $this->orderLock->lockFor(
                $order,
                $user,
                'planRemnant',
                "No se puede quitar un saldo de una orden en estado ':status'.",
            );

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

            $lockedLine->delete();
        }, attempts: 3);
    }
}
