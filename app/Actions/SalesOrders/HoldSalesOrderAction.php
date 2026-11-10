<?php

declare(strict_types=1);

namespace App\Actions\SalesOrders;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesOrders\SalesOrderNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Retiene un pedido «Por aprobar» con un motivo: queda en espera hasta que se apruebe (el cliente se pone al día) o se
 * cancele. No se rechaza: el pedido no se rehace y la cotización de la que vino sigue enlazada.
 */
class HoldSalesOrderAction
{
    public function __construct(
        private readonly SalesOrderDecisionLock $lock,
        private readonly SalesOrderNotifier $notifier,
    ) {}

    public function execute(SalesOrder $order, User $user, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $user, $reason): SalesOrder {
            $lockedOrder = $this->lock->lockFor($order, $user, 'hold');

            $lockedOrder->update([
                'status' => SalesOrderStatus::OnHold,
                'held_by' => $user->id,
                'held_at' => now(),
                'hold_reason' => $reason,
            ]);

            $this->notifier->onHold($lockedOrder, $user);

            return $lockedOrder;
        }, attempts: 3);
    }
}
