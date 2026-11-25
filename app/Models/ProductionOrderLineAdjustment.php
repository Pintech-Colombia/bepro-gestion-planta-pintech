<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditDescription;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $production_order_id
 * @property int $raw_material_id
 * @property string $quantity
 * @property string $reason
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductionOrder $productionOrder
 * @property-read RawMaterial $rawMaterial
 * @property-read User $createdBy
 */
#[Fillable([
    'production_order_id',
    'raw_material_id',
    'quantity',
    'reason',
    'notes',
    'created_by',
])]
class ProductionOrderLineAdjustment extends Model
{
    use HasAuditDescription, LogsActivity;

    /** Un ajuste no se edita: se agrega o se quita. */
    protected static $recordEvents = ['created', 'deleted'];

    protected string $auditLabel = 'Ajuste de línea de OP';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    /** Mismo log que la orden: el ajuste se lee junto con ella. Quién lo hizo ya es el causante del registro. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ordenes_produccion')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['raw_material_id', 'quantity', 'reason', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    /** «OP-2026-0045 · S - 12»: buscar el número de la OP trae la orden y sus ajustes. */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(function (): string {
            return implode(' · ', [
                $this->auditRelatedValue('productionOrder', 'order_number') ?? "OP #{$this->production_order_id}",
                $this->auditRelatedValue('rawMaterial', 'code') ?? "MP #{$this->raw_material_id}",
            ]);
        });
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
