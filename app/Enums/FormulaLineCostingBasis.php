<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cómo se costea en la lista de precios una línea de fórmula que es un producto (P14 de «Alcance — PT consumible»).
 * Elegirla es obligatorio desde la pantalla; nula solo en una línea de la carga inicial que la jefa aún no completó.
 */
enum FormulaLineCostingBasis: string
{
    /** A granel, sin envase: opción A del video (saldo, o envase que se reutiliza). */
    case Bulk = 'bulk';

    /** Con el envase y la etiqueta de una presentación del producto: opción B (se vacían envases que no se reutilizan). */
    case Presentation = 'presentation';

    public function label(): string
    {
        return match ($this) {
            self::Bulk => __('Granel (sin envase)'),
            self::Presentation => __('Presentación'),
        };
    }
}
