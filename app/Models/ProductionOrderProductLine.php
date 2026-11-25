<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionOrderProductLineOrigin;
use App\Models\Concerns\HasAuditDescription;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un producto terminado que la OP lleva como ingrediente («Productos a usar»). Sus fuentes dicen de dónde sale cada kilo:
 * saldos (`remnant_consumptions`) o lotes de PT (`production_order_batch_consumptions`), siempre del mismo producto. Las
 * de la fórmula traen sus kilos planeados; las agregadas en la OP no tienen plan y nacen con su primera fuente.
 *
 * @property int $id
 * @property int $production_order_id
 * @property int $product_id
 * @property int|null $step_order
 * @property string|null $planned_kg
 * @property string|null $actual_kg
 * @property ProductionOrderProductLineOrigin $origin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductionOrder $productionOrder
 * @property-read Product $product
 * @property-read Collection|RemnantConsumption[] $remnantConsumptions
 * @property-read Collection|ProductionOrderBatchConsumption[] $batchConsumptions
 */
#[Fillable([
    'production_order_id',
    'product_id',
    'step_order',
    'planned_kg',
    'actual_kg',
    'origin',
])]
class ProductionOrderProductLine extends Model
{
    use HasAuditDescription, LogsActivity;

    protected string $auditLabel = 'Línea de producto de OP';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    /** Mismo log que la orden: la línea se lee junto con ella. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ordenes_produccion')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['production_order_id', 'product_id', 'step_order', 'planned_kg', 'actual_kg', 'origin'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'planned_kg' => 'decimal:4',
            'actual_kg' => 'decimal:4',
            'origin' => ProductionOrderProductLineOrigin::class,
        ];
    }

    /** «OP-2026-0001 · ESMALTE BLANCO»: se entiende y se busca en el log sin cruzar ids. */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(function (): string {
            return implode(' · ', [
                $this->auditRelatedValue('productionOrder', 'order_number') ?? "OP #{$this->production_order_id}",
                $this->auditRelatedValue('product', 'name') ?? "Producto #{$this->product_id}",
            ]);
        });
    }

    public function isAdded(): bool
    {
        return $this->origin === ProductionOrderProductLineOrigin::Added;
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function remnantConsumptions(): HasMany
    {
        return $this->hasMany(RemnantConsumption::class, 'production_order_product_line_id');
    }

    public function batchConsumptions(): HasMany
    {
        return $this->hasMany(ProductionOrderBatchConsumption::class, 'production_order_product_line_id');
    }
}
