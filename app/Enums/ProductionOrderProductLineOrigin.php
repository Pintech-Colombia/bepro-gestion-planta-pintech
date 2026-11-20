<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * De dónde salió una línea de «Productos a usar» de una OP.
 */
enum ProductionOrderProductLineOrigin: string
{
    /** Copiada de la fórmula al crear la OP, con sus kilos planeados. */
    case Formula = 'formula';

    /** Agregada en la OP al usar un saldo o un lote de un producto que la fórmula no lleva: sin kilos planeados. */
    case Added = 'added';

    public function label(): string
    {
        return match ($this) {
            self::Formula => __('De la fórmula'),
            self::Added => __('Agregada en la orden'),
        };
    }
}
