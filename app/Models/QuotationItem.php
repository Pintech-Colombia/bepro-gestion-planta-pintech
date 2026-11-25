<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\WholeUnits;
use App\Enums\QuotationItemType;
use App\Models\Concerns\HasAuditDescription;
use Database\Factories\QuotationItemFactory;
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
 * @property int $quotation_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property QuotationItemType|null $type
 * @property string|null $description
 * @property string|null $color
 * @property int $quantity
 * @property string $list_unit_price
 * @property string $price_adjustment_pct
 * @property string $unit_price
 * @property string $subtotal
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Quotation $quotation
 * @property-read Product $product
 * @property-read ProductVariant $productVariant
 */
#[Fillable([
    'quotation_id',
    'product_id',
    'product_variant_id',
    'type',
    'description',
    'color',
    'quantity',
    'list_unit_price',
    'price_adjustment_pct',
    'unit_price',
    'subtotal',
    'sort_order',
])]
class QuotationItem extends Model
{
    /** @use HasFactory<QuotationItemFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    protected string $auditLabel = 'Línea de cotización';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    protected bool $auditFeminine = true;

    /**
     * Mismo log que la cotización: la línea se lee junto con ella. Crear la cotización no registra sus líneas (la
     * cotización ya deja su entrada, como el pedido con las suyas); al editarla, `QuotationService` empareja las líneas
     * y cada una que cambia, se agrega o se quita deja la suya. Se registra lo que la persona define en la línea: el
     * ajuste y el precio unitario sí; el precio de lista (del catálogo) y el subtotal (calculado) no. Si el precio de lista
     * cambió desde el último guardado, guardar recalcula el ajuste para el precio que quedó (y una descripción vacía toma
     * la del producto): ese cambio sí queda, a nombre de quien guardó, porque el dato de la línea cambió en ese guardado.
     * **El orden no se registra**: quitar una línea del medio lo correría en todas las de abajo.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cotizaciones')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['product_variant_id', 'type', 'description', 'color', 'quantity', 'price_adjustment_pct', 'unit_price'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'quantity' => WholeUnits::class,
            'list_unit_price' => 'decimal:4',
            'price_adjustment_pct' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'sort_order' => 'integer',
            'type' => QuotationItemType::class,
        ];
    }

    /** «12 · ESMALTE BLANCO · GALÓN · RAL 7016»: el número de la cotización trae la cotización y sus líneas. */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(function (): string {
            return collect([
                $this->auditRelatedValue('quotation', 'quotation_number') ?? "Cotización #{$this->quotation_id}",
                $this->auditRelatedValue('product', 'name'),
                $this->auditRelatedValue('productVariant', 'presentation_label'),
                $this->auditColor(),
            ])
                ->filter(fn (mixed $part): bool => $part !== null && $part !== '')
                ->implode(' · ');
        });
    }

    /**
     * El color que nombra a la línea. Al editarlo, el anterior: es el que tenía la línea que se cambió, y buscarlo en la
     * auditoría encuentra el cambio. En `updated` el original todavía no se ha sincronizado (Laravel lo hace en `saved`).
     */
    private function auditColor(): ?string
    {
        return $this->exists && ! $this->wasRecentlyCreated && $this->isDirty('color')
            ? $this->getOriginal('color')
            : $this->color;
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
