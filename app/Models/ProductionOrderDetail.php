<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditDescription;
use Database\Factories\ProductionOrderDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $production_order_id
 * @property int|null $batch_id
 * @property int $raw_material_id
 * @property int $step_order
 * @property string $planned_quantity
 * @property string|null $conversion_factor
 * @property string|null $actual_quantity
 * @property string $unit_cost
 * @property string $total_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductionOrder $productionOrder
 * @property-read InventoryBatch|null $batch
 * @property-read RawMaterial $rawMaterial
 */
#[Fillable([
    'production_order_id',
    'batch_id',
    'raw_material_id',
    'step_order',
    'planned_quantity',
    'conversion_factor',
    'actual_quantity',
    'unit_cost',
    'total_cost',
])]
class ProductionOrderDetail extends Model
{
    /** @use HasFactory<ProductionOrderDetailFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    /** La creación ya queda en la OP, que nace con sus líneas: aquí solo lo que cambia después. */
    protected static $recordEvents = ['updated'];

    protected string $auditLabel = 'Línea de MP de OP';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    protected bool $auditFeminine = true;

    /** Mismo log que la orden: la línea se lee junto con ella. Solo la cantidad real: es lo que escribe una persona. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ordenes_produccion')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['actual_quantity'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'planned_quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:6',
            'actual_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    /** «OP-2026-0045 · S - 12»: buscar el número de la OP trae la orden y sus líneas. */
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }
}
