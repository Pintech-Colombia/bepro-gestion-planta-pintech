<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RemnantMovementType;
use App\Models\Concerns\HasAuditDescription;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un movimiento del historial de un saldo de PT. Lo escribe solo `RemnantLedger`; no se edita ni se borra.
 *
 * @property int $id
 * @property int $production_remnant_id
 * @property RemnantMovementType $type
 * @property string $quantity_kg sin signo, salvo en la corrección
 * @property string $balance_after_kg
 * @property int|null $production_order_id
 * @property int|null $remnant_consumption_id
 * @property int|null $production_order_batch_consumption_id el lote abierto cuyo resto volvió al saldo
 * @property string|null $notes
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductionRemnant $remnant
 * @property-read ProductionOrder|null $productionOrder
 * @property-read RemnantConsumption|null $consumption
 * @property-read ProductionOrderBatchConsumption|null $batchConsumption
 * @property-read User|null $user
 */
#[Fillable([
    'production_remnant_id',
    'type',
    'quantity_kg',
    'balance_after_kg',
    'production_order_id',
    'remnant_consumption_id',
    'production_order_batch_consumption_id',
    'notes',
    'user_id',
])]
class ProductionRemnantMovement extends Model
{
    use HasAuditDescription, LogsActivity;

    /** Un libro: solo se crea. */
    protected static $recordEvents = ['created'];

    protected string $auditLabel = 'Movimiento de saldo';

    protected string $auditIdentifierAttribute = 'id';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('saldos_produccion')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly([
                'production_remnant_id',
                'type',
                'quantity_kg',
                'balance_after_kg',
                'production_order_id',
                'notes',
            ])
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'type' => RemnantMovementType::class,
            'quantity_kg' => 'decimal:4',
            'balance_after_kg' => 'decimal:4',
        ];
    }

    /**
     * Si el movimiento bajó los kilos del saldo: un consumo o una baja, o una corrección hacia abajo (la tabla guarda los
     * kilos sin signo salvo en la corrección).
     */
    public function decreasesBalance(): bool
    {
        return $this->type->isOutgoing() || str_starts_with((string) $this->quantity_kg, '-');
    }

    public function remnant(): BelongsTo
    {
        return $this->belongsTo(ProductionRemnant::class, 'production_remnant_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(RemnantConsumption::class, 'remnant_consumption_id');
    }

    public function batchConsumption(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderBatchConsumption::class, 'production_order_batch_consumption_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
