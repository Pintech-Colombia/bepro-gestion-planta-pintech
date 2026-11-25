<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Provides standardized Spanish audit descriptions for models using Spatie's LogsActivity.
 *
 * Models using this trait MUST define:
 * - `string $auditLabel` — Human-readable name for the model (e.g., 'Producto', 'Bodega')
 * - `string $auditIdentifierAttribute` — The attribute used as identifier (e.g., 'name', 'code', 'id')
 *
 * Optionally define:
 * - `bool $auditFeminine` — Set to true for feminine nouns (default: false)
 *
 * @example
 * ```php
 * class Product extends Model
 * {
 *     use LogsActivity, HasAuditDescription;
 *
 *     protected string $auditLabel = 'Producto';
 *     protected string $auditIdentifierAttribute = 'name';
 * }
 * ```
 */
trait HasAuditDescription
{
    /**
     * @var array<string, array{masculine: string, feminine: string}>
     */
    private static array $eventTranslations = [
        'created' => ['masculine' => 'creado', 'feminine' => 'creada'],
        'updated' => ['masculine' => 'actualizado', 'feminine' => 'actualizada'],
        'deleted' => ['masculine' => 'eliminado', 'feminine' => 'eliminada'],
    ];

    /**
     * Generate a standardized Spanish audit description.
     *
     * Output format: `{Label} "{identifier}" {translated_event}`
     * Examples:
     *   - `Producto "Pintura Blanca" creado`
     *   - `Bodega "Fábrica Principal" creada`
     *   - `Orden de producción "OP-00042" eliminada`
     */
    public function getAuditDescription(string $eventName): string
    {
        $label = property_exists($this, 'auditLabel') ? $this->auditLabel : class_basename($this);
        $identifier = $this->resolveAuditIdentifier();
        $translatedEvent = $this->translateEvent($eventName);

        if ($identifier !== null && $identifier !== '') {
            return "{$label} \"{$identifier}\" {$translatedEvent}";
        }

        return "{$label} {$translatedEvent}";
    }

    /**
     * El atributo que nombra al registro en la auditoría. La pantalla lo usa también para mostrar los IDs que apuntan a
     * él («materia prima: S - 12» en vez de «raw_material_id: 7»).
     */
    public function auditIdentifierKey(): ?string
    {
        return property_exists($this, 'auditIdentifierAttribute') ? $this->auditIdentifierAttribute : null;
    }

    /**
     * Un dato de una relación para el identificador de la auditoría. Usa la relación si ya está cargada y, si no, la
     * consulta sin cargarla: un `loadMissing('relacion:id,columna')` dejaría la relación a medias en el modelo, y el
     * código que sigue después del guardado leería nulos en las demás columnas.
     */
    protected function auditRelatedValue(string $relation, string $column): mixed
    {
        if ($this->relationLoaded($relation)) {
            return $this->getRelation($relation)?->getAttribute($column);
        }

        return $this->{$relation}()->value($column);
    }

    /**
     * Resolve the identifier value for the audit description.
     */
    private function resolveAuditIdentifier(): ?string
    {
        if (! property_exists($this, 'auditIdentifierAttribute')) {
            return null;
        }

        $attribute = $this->auditIdentifierAttribute;
        $value = $this->getAttribute($attribute);

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Translate the Spatie event name to Spanish with correct grammatical gender.
     */
    private function translateEvent(string $eventName): string
    {
        $isFeminine = property_exists($this, 'auditFeminine') && $this->auditFeminine === true;
        $gender = $isFeminine ? 'feminine' : 'masculine';

        return self::$eventTranslations[$eventName][$gender] ?? $eventName;
    }
}
