<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasTone;

/**
 * Qué le pasó a un saldo de PT en un movimiento de su historial. Lo escribe solo `RemnantLedger`.
 */
enum RemnantMovementType: string implements HasTone
{
    /** Quedó como sobrante al completar su OP. */
    case Birth = 'birth';

    /** Se usó en otra OP, al completarla. */
    case Consumption = 'consumption';

    /** Se dio de baja (se secó, se dañó, se botó): queda en 0 kg. */
    case WriteOff = 'write_off';

    /** Se corrigieron sus kilos a lo que se pesó: el único tipo con signo. */
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Birth => __('Nacimiento'),
            self::Consumption => __('Consumo en OP'),
            self::WriteOff => __('Baja'),
            self::Correction => __('Corrección'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Birth => Tone::Success,
            self::Consumption,
            self::WriteOff => Tone::Warning,
            self::Correction => Tone::Neutral,
        };
    }

    /** Si el movimiento resta kilos (sin contar la corrección, que lleva su propio signo). */
    public function isOutgoing(): bool
    {
        return match ($this) {
            self::Consumption,
            self::WriteOff => true,
            self::Birth,
            self::Correction => false,
        };
    }
}
