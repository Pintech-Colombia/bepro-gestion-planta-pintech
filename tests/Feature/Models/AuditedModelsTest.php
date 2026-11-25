<?php

declare(strict_types=1);

use App\Models\Alert;
use App\Models\ProductionCost;
use App\Models\ShrinkWrapItem;
use Spatie\Activitylog\Traits\LogsActivity;

/** Modelos que no registran auditoría, con la razón. Agregar uno exige justificarlo aquí. */
const AUDIT_EXEMPT_MODELS = [
    Alert::class => 'La crea el sistema; la fila misma guarda quién la resolvió y cuándo',
    ProductionCost::class => 'Lo calcula el sistema (recálculo o al completar la OP); ninguna persona lo escribe',
    ShrinkWrapItem::class => 'Sale de la receta del tipo al registrar el termoencogido, que ya se audita; no se edita',
];

test('todo modelo que no esté exento usa LogsActivity', function () {
    $models = appModelClasses();
    $errors = [];

    foreach ($models as $class) {
        if (! array_key_exists($class, AUDIT_EXEMPT_MODELS) && ! in_array(LogsActivity::class, class_uses_recursive($class), true)) {
            $errors[] = "{$class} no registra auditoría: agrégale LogsActivity o justifícalo en AUDIT_EXEMPT_MODELS.";
        }
    }

    expect($models)->not->toBeEmpty()
        ->and($errors)->toBe([], implode(PHP_EOL, $errors));
});

test('ningún modelo exento usa LogsActivity y todos existen', function () {
    $models = appModelClasses();
    $errors = [];

    foreach (array_keys(AUDIT_EXEMPT_MODELS) as $class) {
        if (! in_array($class, $models, true)) {
            $errors[] = "{$class} ya no existe en app/Models: quítalo de AUDIT_EXEMPT_MODELS.";
        } elseif (in_array(LogsActivity::class, class_uses_recursive($class), true)) {
            $errors[] = "{$class} ya registra auditoría: quítalo de AUDIT_EXEMPT_MODELS.";
        }
    }

    expect($errors)->toBe([], implode(PHP_EOL, $errors));
});
