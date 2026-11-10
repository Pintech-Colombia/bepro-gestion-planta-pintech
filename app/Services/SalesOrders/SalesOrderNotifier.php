<?php

declare(strict_types=1);

namespace App\Services\SalesOrders;

use App\Enums\Permission;
use App\Models\SalesOrder;
use App\Models\User;
use App\Notifications\SalesOrders\SalesOrderApproved;
use App\Notifications\SalesOrders\SalesOrderAwaitingApproval;
use App\Notifications\SalesOrders\SalesOrderNotification;
use App\Notifications\SalesOrders\SalesOrderOnHold;
use App\Notifications\SalesOrders\SalesOrderReleased;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Único lugar que decide a quién le llega cada aviso de un pedido y lo envía.
 *
 * Siempre a usuarios activos que pueden abrir el pedido (`SalesOrderPolicy::view`: el aviso lleva a él, y sin acceso
 * daría 403), nunca a quien hizo la acción. Se envía después del commit: si la transacción falla, no queda un aviso de
 * algo que no pasó (fuera de una transacción, `afterCommit` envía en el acto).
 */
class SalesOrderNotifier
{
    /**
     * Pedido nuevo: a quien recibe los avisos de pedidos por aprobar.
     */
    public function awaitingApproval(SalesOrder $order, ?User $actor): void
    {
        $this->sendAfterCommit(
            $order,
            fn (): Collection => $this->withPermission(Permission::SalesOrdersReceiveApprovalRequests),
            new SalesOrderAwaitingApproval($order),
            $actor,
        );
    }

    /**
     * Pedido aprobado: a la planta (quien crea órdenes de producción). Si estaba retenido, además «liberado» a quien lo
     * creó, que recibió el aviso de retenido.
     */
    public function approved(SalesOrder $order, User $actor, bool $wasOnHold): void
    {
        $this->sendAfterCommit(
            $order,
            fn (): Collection => $this->withPermission(Permission::ProductionOrdersCreate),
            new SalesOrderApproved($order),
            $actor,
        );

        if ($wasOnHold) {
            $this->sendAfterCommit($order, fn (): Collection => $this->creatorOf($order), new SalesOrderReleased($order), $actor);
        }
    }

    /**
     * Pedido retenido: a quien lo creó (en uno convertido, el creador de la cotización).
     */
    public function onHold(SalesOrder $order, User $actor): void
    {
        $this->sendAfterCommit($order, fn (): Collection => $this->creatorOf($order), new SalesOrderOnHold($order), $actor);
    }

    /**
     * @param  \Closure(): Collection<int, User>  $recipients  Se resuelve ya confirmada la transacción.
     */
    private function sendAfterCommit(
        SalesOrder $order,
        \Closure $recipients,
        SalesOrderNotification $notification,
        ?User $actor,
    ): void {
        DB::afterCommit(function () use ($order, $recipients, $notification, $actor): void {
            $users = $recipients()
                ->reject(fn (User $user): bool => $actor !== null && $user->is($actor))
                ->filter(fn (User $user): bool => $user->can('view', $order))
                ->values();

            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            }
        });
    }

    /**
     * Usuarios activos con el permiso, por rol o directo. Es la consulta del scope `permission()` de Spatie, escrita
     * con `whereHas` para que no lance una excepción cuando el permiso aún no está sembrado.
     *
     * @return Collection<int, User>
     */
    private function withPermission(Permission $permission): Collection
    {
        $hasPermission = fn (Builder $query): Builder => $query->where('name', $permission->value);

        return User::query()
            ->active()
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('permissions', $hasPermission)
                ->orWhereHas('roles.permissions', $hasPermission))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function creatorOf(SalesOrder $order): Collection
    {
        $creator = User::query()->active()->find($order->created_by);

        return $creator === null ? collect() : collect([$creator]);
    }
}
