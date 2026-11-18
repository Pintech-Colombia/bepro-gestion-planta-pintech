<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormulaLineCostingBasis;
use App\Enums\TextFormat;
use App\Models\Concerns\HasAuditDescription;
use App\Services\DecimalCalculator;
use Database\Factories\FormulaFactory;
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
 * @property int $version
 * @property string|null $name
 * @property bool $is_active
 * @property bool $is_price_reference
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read User $createdBy
 * @property-read Collection|FormulaDetail[] $details
 * @property-read Collection|ProductionOrder[] $productionOrders
 * @property-read Collection|ProductionCost[] $productionCosts
 */
#[Fillable([
    'product_id',
    'version',
    'name',
    'is_active',
    'is_price_reference',
    'notes',
    'created_by',
])]
class Formula extends Model
{
    /** @use HasFactory<FormulaFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    protected string $auditLabel = 'Fórmula';

    protected string $auditIdentifierAttribute = 'version';

    protected bool $auditFeminine = true;

    /** Los mismos defaults de la base: la regla de `booted()` necesita saber si una fórmula nueva queda activa. */
    protected $attributes = [
        'is_active' => true,
        'is_price_reference' => false,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('formulas')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['product_id', 'version', 'name', 'is_active', 'is_price_reference', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Dos reglas para toda fórmula que se guarda con eventos de modelo (pantalla, factories, tinker):
     * - sin versión, toma la siguiente de su producto;
     * - la primera fórmula activa de un producto queda de referencia de precio, y también la que se reactiva en un
     *   producto que se quedó sin referencia (D-FOR1).
     * Quien crea o reactiva desde la pantalla bloquea antes el producto (acciones de fórmulas), así que dos peticiones a la
     * vez no sacan la misma versión ni reclaman las dos la referencia. Fuera de ellas, los índices únicos frenan la segunda.
     *
     * Ojo: sin eventos (`WithoutModelEvents` en DatabaseSeeder, `saveQuietly`) estas reglas no actúan. Quien cargue
     * fórmulas así marca la versión y la referencia a mano (FormulaSeeder), o el producto queda sin costear.
     */
    protected static function booted(): void
    {
        static::creating(function (Formula $formula): void {
            $formula->version ??= (int) static::query()->where('product_id', $formula->product_id)->max('version') + 1;
        });

        static::saving(function (Formula $formula): void {
            // Solo una fórmula nueva o una que cambia de estado puede reclamar la referencia: editar las notas de una
            // activa no consulta nada.
            $mayClaim = ! $formula->exists || $formula->isDirty('is_active');

            if ($mayClaim && $formula->is_active && ! $formula->is_price_reference && ! $formula->productHasOtherPriceReference()) {
                $formula->is_price_reference = true;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'name' => TextFormat::Name->cast(),
            'is_active' => 'boolean',
            'is_price_reference' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active formulas.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * La fórmula con la que se costea el producto (una por producto).
     */
    public function scopePriceReference(Builder $query): void
    {
        $query->where('is_price_reference', true);
    }

    /**
     * Deja las líneas de la fórmula como las recibe, en ese orden: el paso es la posición (1, 2, 3…). La misma materia
     * prima puede ir en varios pasos (D-FOR2). Quien llama abre la transacción.
     *
     * Las líneas se emparejan **por contenido, no por posición**, como un comparador de texto: la secuencia común más
     * larga de materias primas (antes y ahora, en orden) conserva sus líneas, que toman la cantidad, la unidad y el paso
     * nuevos; la auditoría (`logOnlyDirty`, sin el paso) registra solo lo que cambió, y guardar sin cambios no deja nada.
     * Así, quitar o agregar un paso del medio deja **un** borrado o **una** creación, no un cambio en cada paso que se
     * corre. Cambiar la materia prima de un paso queda como borrado de la vieja y creación de la nueva. Con la misma
     * materia prima en varios pasos (D-FOR2), entre dos emparejamientos posibles gana el que más líneas deja idénticas
     * (misma cantidad y unidad): con [A 2, A 3] → [A 3] se borra la de 2, no se «cambia» 2 → 3 y se borra la otra.
     *
     * Toda línea sin pareja se borra una por una, por id (el índice `(formula_id, step_order)` no es único: un paso
     * repetido de datos viejos también se limpia), para que cada borrado quede en la auditoría con sus valores. Los
     * borrados van primero: en el log, un cambio de ingrediente se lee «eliminada» y luego «creada».
     *
     * @param  array<int, array{raw_material_id?:int|null, product_id?:int|null, costing_basis?:string|null, costing_variant_id?:int|null, quantity:string|int|float, unit_of_measure_id:int}>  $details
     */
    public function replaceDetails(array $details): void
    {
        $details = array_map(fn (array $detail): array => self::normalizedLine($detail), array_values($details));
        $existing = $this->details()->get()->values();
        $ingredients = $this->auditIngredients(collect($details)->merge($existing->map(fn (FormulaDetail $line): array => [
            'raw_material_id' => $line->raw_material_id,
            'product_id' => $line->product_id,
        ]))->all());

        // La pareja es por ingrediente: una materia prima (`m:12`) y un producto (`p:12`) con el mismo id nunca se emparejan.
        // La firma completa suma cantidad, unidad y forma de costeo, para preferir las líneas idénticas.
        $calculator = app(DecimalCalculator::class);
        $signature = function (array $line) use ($calculator): array {
            $ingredient = $line['product_id'] !== null ? 'p:'.(int) $line['product_id'] : 'm:'.(int) $line['raw_material_id'];

            return [
                $ingredient,
                $ingredient.'|'.$calculator->round((string) $line['quantity'], 4).'|'.(int) $line['unit_of_measure_id']
                    .'|'.($line['costing_basis'] ?? '').'|'.($line['costing_variant_id'] ?? ''),
            ];
        };

        $pairs = $this->pairLines(
            $existing->map(fn (FormulaDetail $line): array => $signature([
                'raw_material_id' => $line->raw_material_id,
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_of_measure_id' => $line->unit_of_measure_id,
                'costing_basis' => $line->costing_basis?->value,
                'costing_variant_id' => $line->costing_variant_id,
            ]))->all(),
            array_map($signature, $details),
        );
        $pairedExisting = array_flip($pairs);

        $existing->each(function (FormulaDetail $line, int $index) use ($pairedExisting, $ingredients): void {
            if (! isset($pairedExisting[$index])) {
                $this->prepareLineForAudit($line, $ingredients);
                $line->delete();
            }
        });

        foreach ($details as $index => $detail) {
            $line = isset($pairs[$index]) ? $existing[$pairs[$index]] : new FormulaDetail(['formula_id' => $this->id]);

            $line->fill([...$detail, 'step_order' => $index + 1]);
            $this->prepareLineForAudit($line, $ingredients);
            $line->save();
        }
    }

    /**
     * Empareja las líneas viejas con las nuevas por la secuencia común más larga de materias primas (en orden). Entre
     * las secuencias igual de largas, prefiere la que deja más líneas idénticas: cada pareja vale `$weight` por la
     * materia prima (más que todas las idénticas juntas, así que nunca se sacrifica una pareja) y 1 más si además es
     * idéntica.
     *
     * @param  array<int, array{0: string, 1: string}>  $old  [ingrediente, firma completa] de cada línea vieja, por posición
     * @param  array<int, array{0: int, 1: string}>  $new  lo mismo de cada línea nueva
     * @return array<int, int> posición nueva => posición vieja, solo las emparejadas
     */
    private function pairLines(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $weight = min($oldCount, $newCount) + 1;
        $score = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        $pairScore = fn (int $i, int $j): int => $weight + ($old[$i][1] === $new[$j][1] ? 1 : 0);

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $score[$i][$j] = max(
                    $old[$i][0] === $new[$j][0] ? $score[$i + 1][$j + 1] + $pairScore($i, $j) : 0,
                    $score[$i + 1][$j],
                    $score[$i][$j + 1],
                );
            }
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $oldCount && $j < $newCount) {
            if ($old[$i][0] === $new[$j][0] && $score[$i][$j] === $score[$i + 1][$j + 1] + $pairScore($i, $j)) {
                $pairs[$j] = $i;
                $i++;
                $j++;
            } elseif ($score[$i + 1][$j] >= $score[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }

    /**
     * Borra las líneas una por una antes de eliminar la fórmula: la clave foránea las borraría en cascada sin pasar por
     * el modelo, y la composición de la fórmula eliminada no quedaría en la auditoría. Quien llama abre la transacción
     * (si la fórmula no se puede eliminar, los borrados y sus registros se revierten con ella).
     */
    public function deleteDetails(): void
    {
        $lines = $this->details()->get();
        $ingredients = $this->auditIngredients($lines->map(fn (FormulaDetail $line): array => [
            'raw_material_id' => $line->raw_material_id,
            'product_id' => $line->product_id,
        ])->all());

        $lines->each(function (FormulaDetail $line) use ($ingredients): void {
            $this->prepareLineForAudit($line, $ingredients);
            $line->delete();
        });
    }

    /**
     * Una línea como se guarda: materia prima **o** producto, y la forma de costeo solo en la de producto (P14). Una línea
     * de materia prima deja vacías las columnas del producto, aunque el formulario mande algo en ellas.
     *
     * @param  array<string, mixed>  $detail
     * @return array{raw_material_id: int|null, product_id: int|null, costing_basis: string|null, costing_variant_id: int|null, quantity: mixed, unit_of_measure_id: int}
     */
    private static function normalizedLine(array $detail): array
    {
        $productId = isset($detail['product_id']) && $detail['product_id'] !== '' ? (int) $detail['product_id'] : null;
        $basis = $productId !== null && isset($detail['costing_basis']) && $detail['costing_basis'] !== ''
            ? FormulaLineCostingBasis::from((string) $detail['costing_basis'])
            : null;

        return [
            'raw_material_id' => $productId === null ? (int) $detail['raw_material_id'] : null,
            'product_id' => $productId,
            'costing_basis' => $basis?->value,
            'costing_variant_id' => $basis === FormulaLineCostingBasis::Presentation ? (int) $detail['costing_variant_id'] : null,
            'quantity' => $detail['quantity'],
            'unit_of_measure_id' => (int) $detail['unit_of_measure_id'],
        ];
    }

    /**
     * Las materias primas y los productos de las líneas, una consulta para cada uno, para la descripción de la auditoría.
     *
     * @param  array<int, array{raw_material_id?: int|string|null, product_id?: int|string|null}>  $lines
     * @return array{materials: Collection<int, RawMaterial>, products: Collection<int, Product>}
     */
    private function auditIngredients(array $lines): array
    {
        // El producto de la fórmula completo, no solo id y nombre: queda pegado a la fórmula que devuelven las acciones, y
        // un producto a medias daría null sin error a quien lea después otro campo (p. ej. `is_active`).
        $this->loadMissing('product');

        $ids = fn (string $key): array => array_values(array_unique(array_filter(array_map(
            fn (array $line): int => (int) ($line[$key] ?? 0),
            $lines,
        ))));

        return [
            'materials' => RawMaterial::query()->whereKey($ids('raw_material_id'))->get(['id', 'code'])->keyBy('id'),
            'products' => Product::query()->whereKey($ids('product_id'))->get(['id', 'name'])->keyBy('id'),
        ];
    }

    /**
     * Le da a la línea su fórmula (con el producto) y su ingrediente ya cargados: la descripción de la auditoría no
     * consulta nada por línea.
     *
     * @param  array{materials: Collection<int, RawMaterial>, products: Collection<int, Product>}  $ingredients
     */
    private function prepareLineForAudit(FormulaDetail $line, array $ingredients): void
    {
        $line->setRelation('formula', $this);
        $line->setRelation('rawMaterial', $ingredients['materials']->get((int) $line->raw_material_id));
        $line->setRelation('product', $ingredients['products']->get((int) $line->product_id));
    }

    private function productHasOtherPriceReference(): bool
    {
        return static::query()
            ->where('product_id', $this->product_id)
            ->priceReference()
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->exists();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(FormulaDetail::class, 'formula_id')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class, 'formula_id');
    }

    public function productionCosts(): HasMany
    {
        return $this->hasMany(ProductionCost::class, 'formula_id');
    }
}
