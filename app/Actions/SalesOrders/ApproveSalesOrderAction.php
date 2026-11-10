<?php

declare(strict_types=1);

namespace App\Actions\SalesOrders;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesOrders\SalesOrderNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Aprueba un pedido «Por aprobar» o «Retenido»: pasa a «Pendiente» y la planta lo ve como pedido por hacer. Parcial no
 * toca líneas ni cantidades: la nota dice qué se aprobó. De un pedido retenido se conservan los datos de la retención.
 */
class ApproveSalesOrderAction
{
    public function __construct(
        private readonly SalesOrderDecisionLock $lock,
        private readonly SalesOrderNotifier $notifier,
    ) {}

    public function execute(SalesOrder $order, User $user, bool $isPartial, ?string $note): SalesOrder
    {
        return DB::transaction(function () use ($order, $user, $isPartial, $note): SalesOrder {
            $lockedOrder = $this->lock->lockFor($order, $user, 'approve');
            $wasOnHold = $lockedOrder->status === SalesOrderStatus::OnHold;

            $lockedOrder->update([
                'status' => SalesOrderStatus::Pending,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_note' => $note !== null && trim($note) !== '' ? $note : null,
                'is_partial_approval' => $isPartial,
            ]);

            $this->notifier->approved($lockedOrder, $user, $wasOnHold);

            return $lockedOrder;
        }, attempts: 3);
    }
}
