<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los recálculos de costo que corren en la cola. Cada uno deja su propia alerta si falla (`alerts.source_key`), para que
 * terminar bien uno no resuelva la alerta de otro: el del precio de referencia de una materia prima no recalcula los
 * costos que dependen de ella.
 */
enum RecalculationKind: string
{
    /** El precio de referencia de una materia prima, desde sus compras. */
    case ReferencePrice = 'reference-price';

    /** Los productos y presentaciones que usan una materia prima. */
    case RawMaterialCosts = 'raw-material-costs';

    /** Los productos que convierten con una unidad de medida. */
    case UnitCosts = 'unit-costs';

    /** Los productos que llevan un producto en su fórmula. */
    case ProductChain = 'product-chain';

    public function sourceKey(int $id): string
    {
        return $this->keyPrefix().$id;
    }

    public function keyPrefix(): string
    {
        return "recalc:{$this->value}:";
    }
}
