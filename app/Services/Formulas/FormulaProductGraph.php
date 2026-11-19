<?php

declare(strict_types=1);

namespace App\Services\Formulas;

use App\Models\FormulaDetail;
use App\Models\Product;

/**
 * Qué productos lleva cada producto en sus fórmulas **activas** (PT consumible, D1 y D-4 del plan): un producto no puede
 * llevarse a sí mismo, ni directo ni a través de otro. El costo teórico recorre esta cadena (P13), así que un ciclo la
 * volvería infinita.
 */
class FormulaProductGraph
{
    /**
     * El camino del ciclo que se formaría si `$productId` llevara `$ingredientIds` en una fórmula activa, con los nombres
     * de los productos («RAL 7016 → ESMALTE BLANCO → RAL 7016»), o null si no se forma ninguno. `$exceptFormulaId` es la
     * fórmula que se está guardando: sus líneas viejas no cuentan.
     *
     * @param  iterable<int|string|null>  $ingredientIds
     * @return list<string>|null
     */
    public function cycleFor(int $productId, iterable $ingredientIds, ?int $exceptFormulaId = null): ?array
    {
        $ingredients = collect($ingredientIds)->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ingredients->isEmpty()) {
            return null;
        }

        $edges = $this->edges($exceptFormulaId);

        foreach ($ingredients as $ingredientId) {
            $path = $this->pathBetween($ingredientId, $productId, $edges);

            if ($path !== null) {
                return $this->names([$productId, ...$path]);
            }
        }

        return null;
    }

    /**
     * Producto => productos que llevan sus fórmulas activas.
     *
     * @return array<int, list<int>>
     */
    private function edges(?int $exceptFormulaId): array
    {
        return FormulaDetail::query()
            ->join('formulas', 'formulas.id', '=', 'formula_details.formula_id')
            ->where('formulas.is_active', true)
            ->whereNotNull('formula_details.product_id')
            ->when($exceptFormulaId !== null, fn ($query) => $query->where('formulas.id', '!=', $exceptFormulaId))
            ->distinct()
            ->get(['formulas.product_id as from_product_id', 'formula_details.product_id as to_product_id'])
            ->groupBy(fn (FormulaDetail $edge): int => (int) $edge->getAttribute('from_product_id'))
            ->map(fn ($group): array => $group->map(fn (FormulaDetail $edge): int => (int) $edge->getAttribute('to_product_id'))->unique()->values()->all())
            ->all();
    }

    /**
     * El camino de `$from` a `$to` siguiendo las aristas (búsqueda en anchura), con los dos extremos; null si no hay.
     *
     * @param  array<int, list<int>>  $edges
     * @return list<int>|null
     */
    private function pathBetween(int $from, int $to, array $edges): ?array
    {
        $previous = [$from => null];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $to) {
                $path = [];

                for ($node = $to; $node !== null; $node = $previous[$node]) {
                    array_unshift($path, $node);
                }

                return $path;
            }

            foreach ($edges[$current] ?? [] as $next) {
                if (! array_key_exists($next, $previous)) {
                    $previous[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $productIds
     * @return list<string>
     */
    private function names(array $productIds): array
    {
        $names = Product::query()->whereKey(array_unique($productIds))->pluck('name', 'id');

        return array_map(fn (int $id): string => (string) ($names[$id] ?? "#{$id}"), $productIds);
    }
}
