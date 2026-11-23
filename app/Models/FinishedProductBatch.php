<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\WholeUnits;
use App\Models\Concerns\HasAuditDescription;
use App\Models\Concerns\ValidatesProductVariant;
use App\Services\DecimalCalculator;
use Database\Factories\FinishedProductBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int $production_order_id
 * @property int $initial_quantity
 * @property string|null $transfer_unit_cost
 * @property bool $transfer_cost_estimated
 * @property Carbon $entry_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant $productVariant
 * @property-read ProductionOrder $productionOrder
 * @property-read Collection|FinishedProductBatchStock[] $stocks
 * @property-read Collection|FinishedInventoryMovement[] $movements
 * @property-read Collection|ProductionOrderBatchConsumption[] $orderConsumptions
 */
#[Fillable([
    'product_id',
    'product_variant_id',
    'production_order_id',
    'initial_quantity',
    'transfer_unit_cost',
    'transfer_cost_estimated',
    'entry_date',
])]
class FinishedProductBatch extends Model
{
    /** @use HasFactory<FinishedProductBatchFactory> */
    use HasAuditDescription, HasFactory, LogsActivity, ValidatesProductVariant;

    protected string $auditLabel = 'Lote de producto terminado';

    protected string $auditIdentifierAttribute = 'id';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('lotes_producto_terminado')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['product_id', 'product_variant_id', 'production_order_id', 'initial_quantity', 'transfer_unit_cost', 'transfer_cost_estimated', 'entry_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'initial_quantity' => WholeUnits::class,
            // Costo de traslado por unidad, con el CIF del producto, fijado al completar la OP. Estimado: calculado
            // después, con el CIF que tenía el producto al migrar (lotes anteriores a la columna).
            'transfer_unit_cost' => 'decimal:4',
            'transfer_cost_estimated' => 'boolean',
            'entry_date' => 'date:Y-m-d',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(FinishedProductBatchStock::class, 'finished_product_batch_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinishedInventoryMovement::class, 'finished_product_batch_id');
    }

    /** Las OP que usan (o usaron) este lote como ingrediente. */
    public function orderConsumptions(): HasMany
    {
        return $this->hasMany(ProductionOrderBatchConsumption::class, 'finished_product_batch_id');
    }

    /**
     * Los kilos de una unidad del lote: galones de su presentación × peso por galón real de la OP que lo fabricó. La
     * única cuenta de kilos por unidad de un lote: la usan la pantalla, la cantidad que propone el buscador y el consumo al
     * completar (que la fija en la fuente). Sin valor de presentación cuenta 1 galón (la regla de
     * `VariantPricingService`). Nulo si la OP no tiene peso por galón.
     */
    public function kgPerUnit(): ?string
    {
        $this->loadMissing(['productVariant', 'productionOrder']);

        $density = $this->productionOrder?->density_kg_per_gallon;
        $calculator = app(DecimalCalculator::class);

        if ($density === null || ! $calculator->isPositive((string) $density)) {
            return null;
        }

        $gallons = (string) ($this->productVariant?->presentation_value ?? '1');

        return $calculator->round($calculator->mul($gallons, (string) $density, 8), 4);
    }

    /**
     * Scope a query to only include batches that have stock available.
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereHas('stocks', function ($q): void {
            $q->where('quantity', '>', 0);
        });
    }

    /**
     * Scope to order by FIFO (oldest first).
     */
    public function scopeFifoOrder(Builder $query): void
    {
        // Columnas calificadas: el orden sirve también en consultas que unen el stock y la OP (el listado por lote).
        $query->orderBy($query->qualifyColumn('entry_date'))->orderBy($query->qualifyColumn('id'));
    }
}
