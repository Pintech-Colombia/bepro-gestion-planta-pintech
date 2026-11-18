<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProductionRemnant;
use App\Models\User;

class ProductionRemnantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductionRemnantsView->value);
    }

    public function view(User $user, ProductionRemnant $remnant): bool
    {
        return $user->can(Permission::ProductionRemnantsView->value);
    }

    /**
     * Corregir los kilos a lo que se pesó. También un saldo agotado: pudo quedar mal pesado.
     */
    public function adjust(User $user, ProductionRemnant $remnant): bool
    {
        return $user->can(Permission::ProductionRemnantsAdjust->value);
    }

    /**
     * Dar de baja: el mismo permiso. Si el saldo ya está agotado, o lo tiene planeado una orden abierta, lo dice la acción
     * con el saldo bloqueado (422 con su mensaje): un saldo que se agota con el diálogo abierto no debe dar un 403 mudo.
     */
    public function writeOff(User $user, ProductionRemnant $remnant): bool
    {
        return $this->adjust($user, $remnant);
    }
}
