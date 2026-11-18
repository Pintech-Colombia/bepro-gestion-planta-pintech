<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Activitylog\Traits\LogsActivity;
use WeakMap;

/**
 * Impide guardar un modelo auditado que se leyó con columnas parciales.
 *
 * La auditoría (Spatie, `logOnlyDirty`) compara lo leído contra la fila que vuelve a leer después de guardar. Si un
 * atributo auditado no se leyó (`select([...])`, `get([...])`, `relación:id,code`), lo registra como cambio («categoría:
 * vacío → 1») aunque no haya cambiado. Escuchar `eloquent.updating` en todas las pruebas hace fallar cualquier camino
 * probado que lea a medias y guarde (docs/SISTEMA_AUDITORIA.md).
 */
final class PartialReadAuditGuard
{
    public static function register(Dispatcher $events): void
    {
        // Solo cuenta un modelo que vino de la base (evento `retrieved`). `wasRecentlyCreated` no sirve para saberlo:
        // `actingAs()` lo apaga en el usuario de la prueba. Un modelo recién creado tiene como original solo lo que se le
        // dio al crearlo; ese caso (crear y modificar en la misma petición) queda para el arreglo de fondo en producción
        // (pendiente en «Auditoría de modelos»).
        $retrieved = new WeakMap;

        $events->listen('eloquent.retrieved: *', function (string $event, array $payload) use ($retrieved): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model) {
                $retrieved[$model] = true;
            }
        });

        $events->listen('eloquent.updating: *', function (string $event, array $payload) use ($retrieved): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model && isset($retrieved[$model])) {
                self::ensureFullyRead($model);
            }
        });
    }

    private static function ensureFullyRead(Model $model): void
    {
        if (! in_array(LogsActivity::class, class_uses_recursive($model), true)) {
            return;
        }

        $original = $model->getRawOriginal();

        // `attributesToBeLogged()` es la lista que usa Spatie: resuelve `logOnly`, `logFillable`, `logAll` y `logExcept`.
        /** @var LogsActivity&Model $model */
        foreach ($model->attributesToBeLogged() as $attribute) {
            // `*` y los atributos de una relación (`unidad.nombre`) no son columnas del modelo.
            if ($attribute === '*' || str_contains($attribute, '.')) {
                continue;
            }

            if (! array_key_exists($attribute, $original)) {
                throw new LogicException(sprintf(
                    'Se guarda %s #%s leído sin la columna auditada «%s»: la auditoría la registraría como cambio. '
                    .'Lee la fila completa antes de guardarla, o escribe con query()->update() si no debe auditarse.',
                    $model::class,
                    (string) $model->getKey(),
                    $attribute,
                ));
            }
        }
    }
}
