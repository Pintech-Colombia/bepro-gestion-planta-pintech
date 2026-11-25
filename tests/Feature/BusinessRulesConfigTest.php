<?php

declare(strict_types=1);

use App\Services\RawMaterialReferencePriceService;

// Las reglas del negocio viven en config/ con un valor fijo: iguales en todos los entornos y cambiadas con un PR, que
// queda revisado y en el historial. Del entorno del servidor solo salen las credenciales y la infraestructura.

/** El código de un archivo de config/ sin sus comentarios: un comentario que mencione env() no cuenta. */
function businessConfigCode(string $file): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents(config_path($file))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

it('no lee del entorno nada de la configuración de producción ni de cotizaciones', function (string $file): void {
    expect(businessConfigCode($file))->not->toMatch('/\benv\s*\(/');
})->with(['production.php', 'quotation.php']);

// app.php y qr.php sí leen del entorno otras claves (infraestructura): se revisa el valor de estas, con cualquier nombre
// de variable.
it('no lee del entorno la zona horaria de la planta ni el portafolio del QR', function (string $file, string $key): void {
    expect(businessConfigCode($file))->not->toMatch("/'{$key}'\\s*=>[^,\\]]*\\benv\\s*\\(/");
})->with([
    'zona horaria de la planta' => ['app.php', 'plant_timezone'],
    'portafolio del QR' => ['qr.php', 'portfolio_url'],
]);

it('usa una política de precio de referencia que existe', function (): void {
    expect(config('production.raw_material_reference_price_policy'))
        ->toBeIn(RawMaterialReferencePriceService::POLICIES);
});
