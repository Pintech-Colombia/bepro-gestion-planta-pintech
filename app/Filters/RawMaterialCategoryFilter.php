<?php

declare(strict_types=1);

namespace App\Filters;

class RawMaterialCategoryFilter extends QueryFilter
{
    protected array $filterable = ['search', 'status', 'type', 'family'];

    protected function search(string $value): void
    {
        $this->applySearch(['code', 'name'], $value);
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
