<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditDescription;
use Database\Factories\FormulaDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $formula_id
 * @property int $raw_material_id
 * @property string $quantity
 * @property int $unit_of_measure_id
 * @property int $step_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Formula $formula
 * @property-read RawMaterial $rawMaterial
 * @property-read UnitOfMeasure $unitOfMeasure
 */
#[Fillable([
    'formula_id',
    'raw_material_id',
    'quantity',
    'unit_of_measure_id',
    'step_order',
])]
class FormulaDetail extends Model
{
    /** @use HasFactory<FormulaDetailFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    protected string $auditLabel = 'Línea de fórmula';

    protected string $auditIdentifierAttribute = 'step_order';

    protected bool $auditFeminine = true;

    /**
     * Mismo log que la fórmula: su composición se lee junto con ella. Editar actualiza cada paso en su lugar
     * (`Formula::replaceDetails`), así que el rastro es lo que cambió en cada paso, y los pasos que sobran o faltan
     * quedan como `created` o `deleted`.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('formulas')
            ->setDescriptionForEvent(fn (string $eventName) => $this->lineAuditDescription($eventName))
            ->logOnly(['formula_id', 'raw_material_id', 'quantity', 'unit_of_measure_id', 'step_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * «Línea 2 de "RAL 7016" v3 (S-11) creada»: con el producto, la versión y el código de la materia prima, para que se
     * entienda y se pueda buscar en el log sin cruzar ids. Quien escribe varias líneas (`Formula::replaceDetails`,
     * `Formula::deleteDetails`) les asigna antes la fórmula con su producto y su materia prima, así que esto no consulta
     * nada por línea; en otro camino (seeders, factories) carga lo que falte. Sin fórmula, queda la descripción genérica.
     *
     * PR D (PT consumible): una línea podrá ser un producto (`raw_material_id` nulo); entonces el paréntesis mostrará el
     * producto en vez de la materia prima.
     */
    private function lineAuditDescription(string $eventName): string
    {
        $this->loadMissing(['formula.product:id,name', 'rawMaterial:id,code']);

        $formula = $this->formula;

        if ($formula === null) {
            return $this->getAuditDescription($eventName);
        }

        $code = $this->rawMaterial?->code;

        return sprintf(
            'Línea %d de "%s" v%d%s %s',
            $this->step_order,
            $formula->product?->name ?? '#'.$formula->product_id,
            $formula->version,
            $code !== null ? " ({$code})" : '',
            $this->translateEvent($eventName),
        );
    }

    protected function casts(): array
    {
        return [
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

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }
}
