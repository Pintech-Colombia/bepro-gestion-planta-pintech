<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwnedRecords;

class SalesOrderPolicy
{
    use AuthorizesOwnedRecords;

    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::SalesOrdersViewOwn->value, Permission::SalesOrdersViewAll->value]);
    }

    public function view(User $user, SalesOrder $salesOrder): bool
    {
        return $this->canAccess($user, $salesOrder);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SalesOrdersCreate->value);
    }

    /**
     * Datos del pedido (contacto, dirección, prioridad, fechas, notas): mientras no ha arrancado («Por aprobar»,
     * «Retenido» o «Pendiente»). Así quien aprueba corrige la dirección o la prioridad antes de aprobar.
     */
    public function update(User $user, SalesOrder $salesOrder): bool
    {
        return in_array($salesOrder->status, [SalesOrderStatus::PendingApproval, SalesOrderStatus::OnHold, SalesOrderStatus::Pending], true)
            && $user->can(Permission::SalesOrdersEdit->value)
            && $this->canAccess($user, $salesOrder);
    }

    /**
     * Aprobar: un pedido «Por aprobar», o uno «Retenido» cuando el cliente se pone al día.
     */
    public function approve(User $user, SalesOrder $salesOrder): bool
    {
        return in_array($salesOrder->status, [SalesOrderStatus::PendingApproval, SalesOrderStatus::OnHold], true)
            && $user->can(Permission::SalesOrdersApprove->value)
            && $this->canAccess($user, $salesOrder);
    }

    /**
     * Retener: solo un pedido «Por aprobar». Uno retenido ya está en espera.
     */
    public function hold(User $user, SalesOrder $salesOrder): bool
    {
        return $salesOrder->status === SalesOrderStatus::PendingApproval
            && $user->can(Permission::SalesOrdersApprove->value)
            && $this->canAccess($user, $salesOrder);
    }

    /**
     * Un pedido «Retenido» solo se cancela por una decisión de cartera: lo hace quien aprueba, no la planta.
     */
    public function updateStatus(User $user, SalesOrder $salesOrder): bool
    {
        return $salesOrder->status->nextTransitions() !== []
            && $user->can(Permission::SalesOrdersUpdateStatus->value)
            && ($salesOrder->status !== SalesOrderStatus::OnHold || $user->can(Permission::SalesOrdersApprove->value))
            && $this->canAccess($user, $salesOrder);
    }

    private function canAccess(User $user, SalesOrder $salesOrder): bool
    {
        return $this->canAccessOwnedRecord(
            $user,
            $salesOrder->created_by,
            Permission::SalesOrdersViewAll,
            Permission::SalesOrdersViewOwn,
        );
    }
}
