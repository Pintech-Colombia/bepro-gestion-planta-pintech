<?php

declare(strict_types=1);

// Las reglas del negocio viven en config/ con un valor fijo: iguales en todos los entornos y cambiadas con un PR, que
// queda revisado y en el historial. Del entorno del servidor solo salen las credenciales y la infraestructura.

it('no lee del entorno nada de la configuración de producción ni de cotizaciones', function (string $file): void {
    expect(str_contains((string) file_get_contents(config_path($file)), 'env('))
        ->toBeFalse("config/{$file} volvió a leer una variable del entorno");
})->with(['production.php', 'quotation.php']);

it('no vuelve a leer del entorno una regla del negocio que salió de él', function (string $variable): void {
    foreach (glob(config_path('*.php')) ?: [] as $file) {
        expect(str_contains((string) file_get_contents($file), $variable))
            ->toBeFalse('config/'.basename($file)." volvió a leer {$variable}");
    }
})->with([
    'RAW_MATERIAL_REFERENCE_PRICE_POLICY',
    'AUTO_UPDATE_VARIANT_PRICE',
    'PRODUCTION_ORDER_LOT_START',
    'QUOTATION_START_NUMBER',
    'APP_PLANT_TIMEZONE',
    'QR_PORTFOLIO_URL',
]);
