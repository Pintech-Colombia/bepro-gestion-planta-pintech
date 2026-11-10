<?php

declare(strict_types=1);

namespace App\Actions\SalesOrders;

use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar y retener pueden pulsarse a la vez (la coordinación comercial y Admin). Se bloquea la fila del pedido y se
 * vuelve a preguntar a la policy con el estado ya bloqueado: la de la petición se evaluó antes de esperar el bloqueo.
 */
class SalesOrderDecisionLock
{
    public function lockFor(SalesOrder $order, User $user, string $ability): SalesOrder
    {
        if (DB::transactionLevel() === 0) {
            // Fuera de una transacción el bloqueo se suelta en la misma consulta: no protegería nada.
            throw new \LogicException('SalesOrderDecisionLock::lockFor debe llamarse dentro de una transacción.');
        }

        $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

        if (Gate::forUser($user)->denies($ability, $lockedOrder)) {
            throw ValidationException::withMessages([
                'sales_order' => __('El pedido ya cambió de estado.'),
            ]);
        }

        return $lockedOrder;
    }
}
