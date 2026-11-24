<?php

declare(strict_types=1);

namespace App\Filters;

class RawMaterialFilter extends QueryFilter
{
    protected array $filterable = ['search', 'status', 'family'];

    protected function search(string $value): void
    {
        // También por categoría: «aditivo» trae los aditivos, «base agua» los de base agua.
        $this->applySearch(['code', 'category.name'], $value);
    }

    protected function status(string $value): void
    {
        $this->applyActiveStatus($value);
    }

    protected function family(string $value): void
    {
        $this->builder->whereHas('category', fn ($category) => $category->where('family_id', $value));
    }
}
