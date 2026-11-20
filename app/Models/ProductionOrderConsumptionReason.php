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
 * El motivo por el que una materia prima se pasó de lo planeado en una OP, más allá de la tolerancia de consumo
 * (`ProductionOrderConsumptionCheck`). Uno por OP y materia prima: lo escribe quien envía a revisión o completa.
 *
 * @property int $id
 * @property int $production_order_id
 * @property int $raw_material_id
 * @property string $reason
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
    'reason',
    'created_by',
])]
class ProductionOrderConsumptionReason extends Model
{
    use HasAuditDescription, LogsActivity;

    protected string $auditLabel = 'Motivo de sobreconsumo';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    /** Mismo log que la orden: el motivo se lee junto con ella. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ordenes_produccion')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['production_order_id', 'raw_material_id', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** «OP-2026-0001 · S-7»: se entiende y se busca en el log sin cruzar ids. */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(function (): string {
            $this->loadMissing(['productionOrder:id,order_number', 'rawMaterial:id,code']);

            return implode(' · ', [
                $this->productionOrder->order_number ?? "OP #{$this->production_order_id}",
                $this->rawMaterial->code ?? "MP #{$this->raw_material_id}",
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
