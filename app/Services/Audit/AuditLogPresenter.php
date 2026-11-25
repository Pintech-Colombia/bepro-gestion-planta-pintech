<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Casts\WholeUnits;
use App\Enums\LabelFormat;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Concerns\HasAuditDescription;
use App\Models\ProductVariant;
use App\Services\TimezoneService;
use App\Support\NumberFormat;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo que muestra la pantalla de Auditoría de cada registro, ya en español: el módulo, el evento y cada cambio con su
 * etiqueta y sus valores formateados según el cast del modelo (fechas en hora de planta, decimales sin ceros de más,
 * Sí/No, enums con su etiqueta e IDs con el nombre del registro al que apuntan). Los nombres se consultan una vez por
 * página, agrupados por modelo.
 *
 * @phpstan-type AuditChange array{field: string, label: string, before: string|null, after: string|null, kind: 'changed'|'set'|'unset'}
 */
final class AuditLogPresenter
{
    /** Datos de los registros manuales que no se muestran: el plan de envasado ya se lee en la presentación. */
    private const HIDDEN_PROPERTIES = ['packaging_plan_id'];

    /** Datos de los registros manuales que son el ID de otro registro. */
    private const PROPERTY_REFERENCES = ['product_variant_id' => ProductVariant::class];

    private const ROLE_PROPERTIES = ['old_role', 'new_role'];

    private const PERMISSION_PROPERTIES = ['permissions', 'added_permissions', 'removed_permissions'];

    /** @var array<class-string, array<string, mixed>> */
    private array $casts = [];

    /** @var array<class-string, array<string, class-string<Model>>> columna FK => modelo al que apunta */
    private array $foreignKeys = [];

    /** @var array<class-string, array<int|string, string>> */
    private array $names = [];

    public function __construct(private readonly TimezoneService $timezone) {}

    /**
     * @param  Collection<int, Activity>  $logs
     * @return Collection<int, array<string, mixed>>
     */
    public function present(Collection $logs): Collection
    {
        $this->loadReferencedNames($logs);

        return $logs->map(function (Activity $log): array {
            $isDiff = $this->isDiff($log);

            return [
                'id' => $log->id,
                'description' => $log->description,
                'event' => $log->event,
                'eventLabel' => self::eventLabel($log->event),
                'module' => self::moduleLabel($log->log_name),
                'causer' => $log->causer ? [
                    'id' => $log->causer->getKey(),
                    'name' => $log->causer->getAttribute('name'),
                    'email' => $log->causer->getAttribute('email'),
                ] : null,
                'detailType' => $isDiff ? 'changes' : 'properties',
                'changes' => $isDiff ? $this->diff($log) : $this->manualProperties($log),
                'createdAt' => $log->created_at?->toIso8601String(),
            ];
        });
    }

    public static function moduleLabel(?string $logName): string
    {
        return $logName !== null && Lang::has("audit.modules.{$logName}") ? __("audit.modules.{$logName}") : (string) $logName;
    }

    public static function eventLabel(?string $event): string
    {
        return $event !== null && Lang::has("audit.events.{$event}") ? __("audit.events.{$event}") : (string) $event;
    }

    /** Etiqueta de un campo auditado: la de la auditoría y, si no hay, la del formulario. Null si no tiene ninguna. */
    public static function attributeLabel(string $field): ?string
    {
        foreach (["audit.attributes.{$field}", "validation.attributes.{$field}"] as $key) {
            if (Lang::has($key)) {
                return Str::ucfirst(__($key));
            }
        }

        return null;
    }

    public static function propertyLabel(string $field): ?string
    {
        return Lang::has("audit.properties.{$field}") ? Str::ucfirst(__("audit.properties.{$field}")) : self::attributeLabel($field);
    }

    /** @return Collection<string, mixed> vacía si el registro no guardó datos */
    private function propertiesOf(Activity $log): Collection
    {
        return $log->properties ?? collect();
    }

    /** Antes y después de un registro del modelo (`properties.old` y `properties.attributes`). */
    private function isDiff(Activity $log): bool
    {
        return $this->propertiesOf($log)->has('attributes') || $this->propertiesOf($log)->has('old');
    }

    /**
     * Solo lo que cambió: un campo con el mismo valor antes y después no se lista (se compara por valor, no por
     * referencia, para que un arreglo igual no cuente como cambio).
     *
     * @return list<AuditChange>
     */
    private function diff(Activity $log): array
    {
        /** @var array<string, mixed> $after */
        $after = (array) $this->propertiesOf($log)->get('attributes', []);
        /** @var array<string, mixed> $before */
        $before = (array) $this->propertiesOf($log)->get('old', []);
        $subject = $this->subjectClass($log);
        $changes = [];

        foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $field) {
            $hasBefore = array_key_exists($field, $before);
            $hasAfter = array_key_exists($field, $after);

            if ($hasBefore && $hasAfter && json_encode($before[$field]) === json_encode($after[$field])) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => self::attributeLabel($field) ?? $field,
                'before' => $hasBefore ? $this->formatAttribute($subject, $field, $before[$field]) : null,
                'after' => $hasAfter ? $this->formatAttribute($subject, $field, $after[$field]) : null,
                'kind' => $hasBefore && $hasAfter ? 'changed' : ($hasAfter ? 'set' : 'unset'),
            ];
        }

        return $changes;
    }

    /**
     * Registros manuales (sesión fallida, roles, permisos, estampitas): una lista de datos con su etiqueta.
     *
     * @return list<AuditChange>
     */
    private function manualProperties(Activity $log): array
    {
        $changes = [];

        foreach ($this->propertiesOf($log)->all() as $field => $value) {
            if (in_array($field, self::HIDDEN_PROPERTIES, true)) {
                continue;
            }

            $changes[] = [
                'field' => (string) $field,
                'label' => self::propertyLabel((string) $field) ?? (string) $field,
                'before' => null,
                'after' => $this->formatProperty((string) $field, $value),
                'kind' => 'set',
            ];
        }

        return $changes;
    }

    private function formatAttribute(?string $subject, string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($subject !== null && isset($this->foreignKeysOf($subject)[$field]) && is_scalar($value)) {
            return $this->nameOf($this->foreignKeysOf($subject)[$field], $value);
        }

        $cast = $subject !== null ? ($this->castsOf($subject)[$field] ?? null) : null;

        return $this->formatValue(is_string($cast) ? $cast : null, $value);
    }

    private function formatProperty(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (true) {
            in_array($field, self::ROLE_PROPERTIES, true) => SystemRole::labelFor((string) $value),
            in_array($field, self::PERMISSION_PROPERTIES, true) => collect((array) $value)
                ->map(fn (mixed $name): string => Permission::tryFrom((string) $name)?->label() ?? (string) $name)
                ->implode(', ') ?: null,
            isset(self::PROPERTY_REFERENCES[$field]) && is_scalar($value) => $this->nameOf(self::PROPERTY_REFERENCES[$field], $value),
            $field === 'format' => LabelFormat::tryFrom((string) $value)?->label() ?? (string) $value,
            $field === 'source' => Lang::has("audit.sources.{$value}") ? __("audit.sources.{$value}") : (string) $value,
            default => $this->formatValue(null, $value),
        };
    }

    private function formatValue(?string $cast, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value) || in_array($cast, ['bool', 'boolean'], true)) {
            return (bool) $value ? 'Sí' : 'No';
        }

        if (is_array($value)) {
            return array_is_list($value) && collect($value)->every(fn (mixed $item): bool => is_scalar($item))
                ? implode(', ', $value)
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($cast === null || ! is_scalar($value)) {
            return (string) (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        $type = Str::before($cast, ':');

        return match (true) {
            $type === 'decimal' => NumberFormat::decimal((string) $value, (int) Str::after($cast, ':')),
            $cast === WholeUnits::class => NumberFormat::units((string) $value),
            in_array($type, ['date', 'immutable_date'], true) => Carbon::parse((string) $value)->format('d/m/Y'),
            in_array($type, ['datetime', 'immutable_datetime', 'timestamp'], true) => $this->timezone->formatPlantDateTime((string) $value),
            enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) => $this->enumLabel($cast, $value),
            default => (string) $value,
        };
    }

    /** @param class-string<BackedEnum> $enum */
    private function enumLabel(string $enum, int|string|float|bool $value): string
    {
        $case = $enum::tryFrom(is_int($value) ? $value : (string) $value);

        return $case !== null && method_exists($case, 'label') ? $case->label() : (string) $value;
    }

    /**
     * Consulta de una vez los nombres de todos los IDs de la página, una consulta por modelo.
     *
     * @param  Collection<int, Activity>  $logs
     */
    private function loadReferencedNames(Collection $logs): void
    {
        /** @var array<class-string<Model>, list<int|string>> $ids */
        $ids = [];

        foreach ($logs as $log) {
            if ($this->isDiff($log)) {
                $subject = $this->subjectClass($log);
                $foreignKeys = $subject !== null ? $this->foreignKeysOf($subject) : [];

                // Antes y después por separado: al unirlos, el valor anterior pisaría al nuevo de la misma clave.
                foreach (['attributes', 'old'] as $version) {
                    foreach ((array) $this->propertiesOf($log)->get($version, []) as $field => $value) {
                        if (isset($foreignKeys[$field]) && is_scalar($value) && $value !== '') {
                            $ids[$foreignKeys[$field]][] = $value;
                        }
                    }
                }

                continue;
            }

            foreach (self::PROPERTY_REFERENCES as $field => $class) {
                $value = $this->propertiesOf($log)->get($field);

                if (is_scalar($value) && $value !== '') {
                    $ids[$class][] = $value;
                }
            }
        }

        foreach ($ids as $class => $classIds) {
            $this->names[$class] = ($this->names[$class] ?? []) + $this->namesFor($class, array_values(array_unique($classIds)));
        }
    }

    /**
     * El nombre de cada registro según su identificador de auditoría (`auditIdentifierAttribute`): una columna se lee
     * con una consulta; un accessor, sobre los modelos de esa consulta. Sin identificador, o si es el ID, no consulta.
     *
     * @param  class-string<Model>  $class
     * @param  list<int|string>  $ids
     * @return array<int|string, string>
     */
    private function namesFor(string $class, array $ids): array
    {
        $model = new $class;
        $attribute = in_array(HasAuditDescription::class, class_uses_recursive($class), true)
            ? $model->auditIdentifierKey()
            : null;

        if ($attribute === null || $attribute === $model->getKeyName()) {
            return [];
        }

        $query = $class::query()->whereKey($ids);

        if ($model->hasGetMutator($attribute) || $model->hasAttributeGetMutator($attribute)) {
            return $query->get()
                ->mapWithKeys(fn (Model $related): array => [$related->getKey() => (string) $related->getAttribute($attribute)])
                ->all();
        }

        return $query->pluck($attribute, $model->getKeyName())
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    /** @param class-string<Model> $class */
    private function nameOf(string $class, int|string|float|bool $id): string
    {
        $name = $this->names[$class][$id] ?? null;

        // Un registro que ya no existe, o un modelo sin nombre propio, se muestra por su número.
        return $name !== null && $name !== '' ? $name : "#{$id}";
    }

    /** @return class-string<Model>|null */
    private function subjectClass(Activity $log): ?string
    {
        if ($log->subject_type === null) {
            return null;
        }

        $class = Relation::getMorphedModel($log->subject_type) ?? $log->subject_type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /**
     * @param  class-string<Model>  $class
     * @return array<string, mixed>
     */
    private function castsOf(string $class): array
    {
        return $this->casts[$class] ??= (new $class)->getCasts();
    }

    /**
     * Las columnas FK del modelo, sacadas de sus relaciones `BelongsTo` (declaradas con su tipo de retorno): así un
     * campo nuevo que apunta a otro registro sale con su nombre sin tocar esta clase.
     *
     * @param  class-string<Model>  $class
     * @return array<string, class-string<Model>>
     */
    private function foreignKeysOf(string $class): array
    {
        if (isset($this->foreignKeys[$class])) {
            return $this->foreignKeys[$class];
        }

        $model = new $class;
        $foreignKeys = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0
                || ! $type instanceof ReflectionNamedType || $type->getName() !== BelongsTo::class) {
                continue;
            }

            $relation = $model->{$method->getName()}();

            if ($relation instanceof BelongsTo) {
                $foreignKeys[$relation->getForeignKeyName()] ??= $relation->getRelated()::class;
            }
        }

        return $this->foreignKeys[$class] = $foreignKeys;
    }
}
