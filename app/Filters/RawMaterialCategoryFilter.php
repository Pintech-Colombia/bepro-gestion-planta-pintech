<?php

declare(strict_types=1);

namespace App\Filters;

class RawMaterialCategoryFilter extends QueryFilter
{
    protected array $filterable = ['search', 'status', 'type', 'family'];

    protected function search(string $value): void
    {
        // Solo el nombre: la familia tiene su filtro (buscarla aquí traería «ADITIVO BASE AGUA» al buscar «BASE AGUA»).
        $this->applySearch(['name'], $value);
    }

    protected function status(string $value): void
    {
        $this->applyActiveStatus($value);
    }

    protected function type(string $value): void
    {
        $this->applyExact('type', $value);
    }

    protected function family(string $value): void
    {
        $this->applyExact('family_id', $value);
    }
}
