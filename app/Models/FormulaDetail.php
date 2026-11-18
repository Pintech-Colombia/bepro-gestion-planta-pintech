<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormulaLineCostingBasis;
use App\Models\Concerns\HasAuditDescription;
use Database\Factories\FormulaDetailFactory;
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
 * @property int $formula_id
 * @property int|null $raw_material_id
 * @property int|null $product_id
 * @property FormulaLineCostingBasis|null $costing_basis
 * @property int|null $costing_variant_id
 * @property string $quantity
 * @property int $unit_of_measure_id
 * @property int $step_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Formula $formula
 * @property-read RawMaterial|null $rawMaterial
 * @property-read Product|null $product
 * @property-read ProductVariant|null $costingVariant
 * @property-read UnitOfMeasure $unitOfMeasure
 */
#[Fillable([
    'formula_id',
    'raw_material_id',
    'product_id',
    'costing_basis',
    'costing_variant_id',
    'quantity',
    'unit_of_measure_id',
    'step_order',
])]
class FormulaDetail extends Model
{
    /** @use HasFactory<FormulaDetailFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    protected string $auditLabel = 'Línea de fórmula';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    protected bool $auditFeminine = true;

    /**
     * Mismo log que la fórmula: su composición se lee junto con ella. Editar empareja las líneas por contenido
     * (`Formula::replaceDetails`), así que el rastro es lo que de verdad cambió: cantidad o unidad de una línea, o una
     * línea que se quitó o se agregó. **El paso no se registra como cambio**: va en la descripción («… · paso 4 · …»),
     * y registrarlo haría que quitar un paso del medio apareciera como un cambio en cada paso que se corre.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('formulas')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['formula_id', 'raw_material_id', 'product_id', 'costing_basis', 'costing_variant_id', 'quantity', 'unit_of_measure_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Identificador de la auditoría, con el patrón de las demás líneas auditadas (`SalesOrderItem`): «RAL 7016 v3 · paso
     * 2 · S-11» → «Línea de fórmula "RAL 7016 v3 · paso 2 · S-11" creada». Lleva el producto, la versión y el código de la
     * materia prima para que se entienda y se pueda buscar en el log sin cruzar ids. Quien escribe varias líneas
     * (`Formula::replaceDetails`, `Formula::deleteDetails`) les asigna antes la fórmula con su producto y su materia prima,
     * así que esto no consulta nada por línea; en otro camino (factories, pruebas) carga lo que falte. Los seeders corren
     * sin eventos de modelo (`WithoutModelEvents`): no dejan registro.
     *
     * Una línea de producto (PT consumible) muestra el nombre del producto en el último tramo.
     */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(function (): string {
            $this->loadMissing(['formula.product', 'rawMaterial:id,code', 'product:id,name']);

            $formula = $this->formula;
            $parts = [
                $formula !== null
                    ? ($formula->product?->name ?? "Producto #{$formula->product_id}")." v{$formula->version}"
                    : "Fórmula #{$this->formula_id}",
                "paso {$this->step_order}",
            ];

            $ingredient = $this->product_id !== null ? $this->product?->name : $this->rawMaterial?->code;

            if ($ingredient !== null) {
                $parts[] = $ingredient;
            }

            return implode(' · ', $parts);
        });
    }

    /** Si la línea es un producto (PT consumible) y no una materia prima. */
    public function isProductLine(): bool
    {
        return $this->product_id !== null;
    }

    protected function casts(): array
    {
        return [
            'costing_basis' => FormulaLineCostingBasis::class,
            'quantity' => 'decimal:4',
            'step_order' => 'integer',
        ];
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class, 'formula_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }

    /** El producto que lleva la línea (PT consumible); nulo en una línea de materia prima. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** La presentación con la que se costea la línea de producto, si se costea así (P14). */
    public function costingVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'costing_variant_id');
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }
}
