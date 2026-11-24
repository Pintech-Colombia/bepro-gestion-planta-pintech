<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TextFormat;
use App\Models\Concerns\HasAuditDescription;
use App\Models\Concerns\SelectableWhenActive;
use Database\Factories\RawMaterialFamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Familia de materia prima (Aditivo, Pigmento, Resina…): agrupa categorías. No tiene tipo de insumo: lo tiene cada
 * categoría, y una familia puede reunir categorías de tipos distintos (envases y empaque secundario).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection|RawMaterialCategory[] $categories
 * @property-read int|null $categories_count
 */
#[Fillable([
    'name',
    'description',
    'is_active',
])]
class RawMaterialFamily extends Model
{
    /** @use HasFactory<RawMaterialFamilyFactory> */
    use HasAuditDescription, HasFactory, LogsActivity, SelectableWhenActive;

    protected string $auditLabel = 'Familia de materia prima';

    protected string $auditIdentifierAttribute = 'name';

    protected bool $auditFeminine = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('familias_materia_prima')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['name', 'description', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'name' => TextFormat::Name->cast(),
            'is_active' => 'boolean',
        ];
    }

    /**
     * Opciones `{value, label}` para los selectores y filtros, ordenadas por nombre.
     *
     * @param  Builder<self>|null  $query  Por defecto, todas (también las inactivas, para filtrar).
     * @return list<array{value: string, label: string}>
     */
    public static function options(?Builder $query = null): array
    {
        return ($query ?? self::query())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (self $family): array => ['value' => (string) $family->id, 'label' => $family->name])
            ->all();
    }

    public function categories(): HasMany
    {
        return $this->hasMany(RawMaterialCategory::class, 'family_id');
    }
}
