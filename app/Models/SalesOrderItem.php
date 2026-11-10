<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\WholeUnits;
use App\Models\Concerns\HasAuditDescription;
use Database\Factories\SalesOrderItemFactory;
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
 * @property int $sales_order_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SalesOrder $salesOrder
 * @property-read Product $product
 * @property-read ProductVariant|null $productVariant
 */
#[Fillable([
    'sales_order_id',
    'product_id',
    'product_variant_id',
    'quantity',
    'color',
])]
class SalesOrderItem extends Model
{
    /** @use HasFactory<SalesOrderItemFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    protected string $auditLabel = 'Línea de pedido';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    protected bool $auditFeminine = true;

    /**
     * Las líneas solo cambian al aprobar parcial: se reducen (cantidad) o pasan al pedido con el resto (pedido). La
     * auditoría guarda qué cantidad tenía cada una y a qué pedido pasó.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('pedidos')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['sales_order_id', 'quantity'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'quantity' => WholeUnits::class,
        ];
    }

    /**
     * Cómo se nombra la línea en la auditoría: «Pedido #20 · Esmalte blanco». El id solo no dice de qué es.
     */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(fn (): string => "Pedido #{$this->sales_order_id} · ".($this->product?->name ?? '?'));
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
