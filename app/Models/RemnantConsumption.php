<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un saldo de PT que usa una OP. Es una línea planeada hasta que la OP se completa: ahí se descuenta del saldo
 * (`applied_at`), con los galones y el costo reales. Mientras está planeada, `quantity_gallons` es un estimado y
 * `consumed_cost` es nulo. `consumed_by` y `consumed_at` son quién la planeó y cuándo.
 *
 * @property int $id
 * @property int $remnant_id
 * @property int|null $target_order_id
 * @property string|null $consumed_cost
 * @property string $quantity_gallons
 * @property string $quantity_kg
 * @property int $consumed_by
 * @property Carbon $consumed_at
 * @property Carbon|null $applied_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductionRemnant $remnant
 * @property-read ProductionOrder|null $targetOrder
 * @property-read User $consumedBy
 */
#[Fillable([
    'remnant_id',
    'target_order_id',
    'consumed_cost',
    'quantity_gallons',
    'quantity_kg',
    'consumed_by',
    'consumed_at',
    'applied_at',
    'notes',
])]
class RemnantConsumption extends Model
{
    protected function casts(): array
    {
        return [
            'consumed_cost' => 'decimal:4',
            'quantity_gallons' => 'decimal:4',
            'quantity_kg' => 'decimal:4',
            'consumed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * Líneas planeadas: todavía no se descontaron del saldo.
     */
    public function scopePlanned(Builder $query): void
    {
        $query->whereNull('applied_at');
    }

    /**
     * Líneas ya descontadas del saldo (al completar la OP).
     */
    public function scopeApplied(Builder $query): void
    {
        $query->whereNotNull('applied_at');
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function remnant(): BelongsTo
    {
        return $this->belongsTo(ProductionRemnant::class, 'remnant_id');
    }

    public function targetOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'target_order_id');
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }
}
