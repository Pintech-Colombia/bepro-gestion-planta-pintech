<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogPresenter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Traits\LogsActivity;

/*
 * La pantalla de Auditoría muestra todo en español. Estas pruebas leen lo que el código audita (los modelos y las
 * llamadas a `activity()`) y fallan si algo nuevo no tiene su etiqueta en `lang/es/audit.php`.
 */

/** @return list<class-string> */
function auditedModels(): array
{
    return array_values(array_filter(
        appModelClasses(),
        fn (string $class): bool => in_array(LogsActivity::class, class_uses_recursive($class), true),
    ));
}

/** Todo el código de `app/`, para buscar las llamadas manuales a `activity()`. */
function appSource(): string
{
    return collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");
}

test('cada campo auditado tiene etiqueta en español', function (): void {
    $missing = [];

    foreach (auditedModels() as $class) {
        foreach ((new $class)->getActivitylogOptions()->logAttributes as $field) {
            if (AuditLogPresenter::attributeLabel($field) === null) {
                $missing[] = class_basename($class).'.'.$field;
            }
        }
    }

    expect($missing)->toBe([], 'Sin etiqueta en lang/es/audit.php (attributes) ni en validation.attributes: '.implode(', ', $missing));
});

test('cada módulo de la auditoría tiene etiqueta en español', function (): void {
    preg_match_all("/activity\\('([a-z_]+)'\\)/", appSource(), $manual);

    $logNames = collect(auditedModels())
        ->map(fn (string $class): ?string => (new $class)->getActivitylogOptions()->logName)
        ->filter()
        ->merge($manual[1])
        ->unique()
        ->values();

    $missing = $logNames->reject(fn (string $name): bool => Lang::has("audit.modules.{$name}"))->values()->all();

    expect($logNames)->not->toBeEmpty()
        ->and($missing)->toBe([], 'Sin etiqueta en lang/es/audit.php (modules): '.implode(', ', $missing));
});

test('cada evento manual y cada dato que guarda tienen etiqueta en español', function (): void {
    $source = appSource();

    preg_match_all("/->event\\('([a-z_]+)'\\)/", $source, $events);
    preg_match_all('/->withProperties\\(\\[(.*?)\\]\\)/s', $source, $blocks);
    preg_match_all("/'([a-z_]+)'\\s*=>/", implode("\n", $blocks[1]), $properties);

    $missingEvents = collect(['created', 'updated', 'deleted', ...$events[1]])
        ->unique()
        ->reject(fn (string $event): bool => Lang::has("audit.events.{$event}"))
        ->values()
        ->all();
    $missingProperties = collect($properties[1])
        ->unique()
        ->reject(fn (string $field): bool => $field === 'packaging_plan_id' || AuditLogPresenter::propertyLabel($field) !== null)
        ->values()
        ->all();

    expect($events[1])->not->toBeEmpty()
        ->and($properties[1])->not->toBeEmpty()
        ->and($missingEvents)->toBe([], 'Eventos sin etiqueta en lang/es/audit.php (events): '.implode(', ', $missingEvents))
        ->and($missingProperties)->toBe([], 'Datos sin etiqueta en lang/es/audit.php (properties): '.implode(', ', $missingProperties));
});
