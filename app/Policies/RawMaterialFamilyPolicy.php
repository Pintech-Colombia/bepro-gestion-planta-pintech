<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RawMaterialFamily;
use App\Models\User;

/**
 * Catálogo de familias de materia prima (docs/MATRIZ_RBAC.md §3, Catálogos). Los `<select>` de los formularios que
 * eligen una familia no pasan por aquí: no exigen `catalogs.view`.
 */
class RawMaterialFamilyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CatalogsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CatalogsCreate->value);
    }

    public function update(User $user, RawMaterialFamily $rawMaterialFamily): bool
    {
        return $user->can(Permission::CatalogsEdit->value);
    }

    public function delete(User $user, RawMaterialFamily $rawMaterialFamily): bool
    {
        return $user->can(Permission::CatalogsDelete->value);
    }
}
