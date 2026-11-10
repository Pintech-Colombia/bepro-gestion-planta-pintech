<?php

declare(strict_types=1);

namespace App\Actions\SalesOrders;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesOrders\SalesOrderNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Aprueba un pedido «Por aprobar» o «Retenido»: pasa a «Pendiente» y la planta lo ve como pedido por hacer. De un
 * pedido retenido se conservan los datos de la retención.
 *
 * Parcial parte el pedido (`SplitSalesOrderRemainder`): lo aprobado se queda en él y lo demás pasa a un pedido nuevo
 * «Retenido», con la nota como motivo. Todo en una transacción, con el pedido y sus líneas bloqueados.
 */
class ApproveSalesOrderAction
{
    public function __construct(
        private readonly SalesOrderDecisionLock $lock,
        private readonly SplitSalesOrderRemainder $splitRemainder,
        private readonly SalesOrderNotifier $notifier,
    ) {}

    /**
     * @param  array<int, int>  $approvedQuantities  Solo si es parcial: unidades que salen ahora, por id de línea.
     */
    public function execute(
        SalesOrder $order,
        User $user,
        bool $isPartial,
        ?string $note,
        array $approvedQuantities = [],
    ): SalesOrder {
        $note = $note !== null && trim($note) !== '' ? $note : null;

        if ($isPartial && $note === null) {
            // El request la exige: es el motivo del pedido retenido con el resto.
            throw new \LogicException('Una aprobación parcial necesita la nota.');
        }

        return DB::transaction(function () use ($order, $user, $isPartial, $note, $approvedQuantities): SalesOrder {
            $lockedOrder = $this->lock->lockFor($order, $user, 'approve');
            $wasOnHold = $lockedOrder->status === SalesOrderStatus::OnHold;

            $remainder = $isPartial
                ? $this->splitRemainder->split($lockedOrder, $user, (string) $note, $approvedQuantities)
                : null;

            $lockedOrder->update([
                'status' => SalesOrderStatus::Pending,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_note' => $note,
                'is_partial_approval' => $isPartial,
            ]);

            $this->notifier->approved($lockedOrder, $user, $wasOnHold, $remainder);

            if ($remainder !== null) {
                $this->notifier->onHold($remainder, $user);
            }

            return $lockedOrder;
        }, attempts: 3);
    }
}
