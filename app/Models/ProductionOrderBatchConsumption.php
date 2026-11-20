<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\WholeUnits;
use App\Models\Concerns\HasAuditDescription;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un lote de PT que usa una OP, en unidades enteras: la fuente de lote de una línea de «Productos a usar». Mientras está
 * sin aplicar compromete sus unidades en la bodega (`AvailableBatchesService`). Al enviar a revisión o completar se
 * guardan los kilos usados y lo que quedó, pesado, en el envase abierto; al completar salen las unidades del inventario
 * («Transformación») y se fijan los kilos por unidad y el costo (`BatchSourceDrawdown`).
 *
 * @property int $id
 * @property int $production_order_id
 * @property int $production_order_product_line_id
 * @property int $finished_product_batch_id
 * @property int $warehouse_id
 * @property int $units
 * @property string|null $used_kg
 * @property string|null $leftover_kg
 * @property string|null $kg_per_unit
 * @property string|null $consumed_cost
 * @property Carbon|null $applied_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductionOrder $productionOrder
 * @property-read ProductionOrderProductLine $productLine
 * @property-read FinishedProductBatch $batch
 * @property-read Warehouse $warehouse
 * @property-read User $createdBy
 */
#[Fillable([
    'production_order_id',
    'production_order_product_line_id',
    'finished_product_batch_id',
    'warehouse_id',
    'units',
    'used_kg',
    'leftover_kg',
    'kg_per_unit',
    'consumed_cost',
    'applied_at',
    'created_by',
])]
class ProductionOrderBatchConsumption extends Model
{
    use HasAuditDescription, LogsActivity;

    protected string $auditLabel = 'Lote de PT usado en OP';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    /** Mismo log que la orden: la fuente se lee junto con ella. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ordenes_produccion')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly([
                'production_order_id',
                'production_order_product_line_id',
                'finished_product_batch_id',
                'warehouse_id',
                'units',
                'used_kg',
                'leftover_kg',
                'kg_per_unit',
                'consumed_cost',
                'applied_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'units' => WholeUnits::class,
            'used_kg' => 'decimal:4',
            'leftover_kg' => 'decimal:4',
            'kg_per_unit' => 'decimal:4',
            'consumed_cost' => 'decimal:4',
            'applied_at' => 'datetime',
        ];
    }

    /** «OP-2026-0002 · lote 1682»: el lote es el de la OP que lo fabricó. */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(function (): string {
            $this->loadMissing(['productionOrder:id,order_number', 'batch:id,production_order_id', 'batch.productionOrder:id,lot_number']);

            return implode(' · ', [
                $this->productionOrder->order_number ?? "OP #{$this->production_order_id}",
                $this->batch?->productionOrder !== null
                    ? "lote {$this->batch->productionOrder->lot_number}"
                    : "lote #{$this->finished_product_batch_id}",
            ]);
        });
    }

    /** Fuentes sin aplicar: todavía comprometen unidades del lote. */
    public function scopePlanned(Builder $query): void
    {
        $query->whereNull('applied_at');
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderProductLine::class, 'production_order_product_line_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinishedProductBatch::class, 'finished_product_batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
