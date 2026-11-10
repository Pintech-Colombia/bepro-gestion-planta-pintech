<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasTone;

/**
 * Alcance de la aprobación de un pedido. Parcial no toca líneas ni cantidades: la nota de la aprobación dice qué se
 * aprobó, y la etiqueta «Parcial» lo avisa junto al estado (detalle, listado y tarjeta del celular).
 */
enum SalesOrderApproval: string implements HasTone
{
    case Full = 'full';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Full => __('Total'),
            self::Partial => __('Parcial'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Full => Tone::Success,
            self::Partial => Tone::Warning,
        };
    }
}
