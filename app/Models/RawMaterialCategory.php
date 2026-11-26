<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RawMaterialType;
use App\Enums\TextFormat;
use App\Models\Concerns\HasAuditDescription;
use App\Models\Concerns\SelectableWhenActive;
use Database\Factories\RawMaterialCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string $name
 * @property string|null $description
 * @property int|null $family_id
 * @property RawMaterialType $type
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RawMaterialFamily|null $family
 * @property-read Collection|RawMaterial[] $rawMaterials
 * @property-read int|null $raw_materials_count
 */
#[Fillable([
    'name',
    'description',
    'family_id',
    'type',
    'is_active',
])]
class RawMaterialCategory extends Model
{
    /** @use HasFactory<RawMaterialCategoryFactory> */
    use HasAuditDescription, HasFactory, LogsActivity, SelectableWhenActive;

    protected string $auditLabel = 'Categoría de materia prima';

    protected string $auditIdentifierAttribute = 'audit_identifier';

    protected bool $auditFeminine = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('categorias_materia_prima')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly(['name', 'description', 'family_id', 'type', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'name' => TextFormat::Name->cast(),
            'type' => RawMaterialType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * «RESINA · EPOXI»: la categoría se identifica por familia y nombre (el nombre solo es único dentro de su familia).
     * La familia se lee sin dejar la relación cargada a medias (`auditRelatedValue`).
     */
    protected function auditIdentifier(): Attribute
    {
        return Attribute::get(fn (): string => collect([$this->auditRelatedValue('family', 'name'), $this->name])
            ->filter(fn (?string $part): bool => $part !== null && $part !== '')
            ->implode(' · '));
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(RawMaterialFamily::class, 'family_id');
    }

    public function rawMaterials(): HasMany
    {
        return $this->hasMany(RawMaterial::class, 'category_id');
    }
}
