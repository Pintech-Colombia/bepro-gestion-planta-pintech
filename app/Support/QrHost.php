<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * El dominio propio del QR de las estampitas (config/qr.php). Es la única fuente: las rutas públicas se atan a él, el
 * middleware IsolateQrHost lo aísla del ERP y el manejador de errores muestra ahí su propia página.
 */
final class QrHost
{
    /**
     * El dominio, ya validado. Se llama al registrar las rutas (también al construir la imagen y al cachearlas).
     *
     * @throws \RuntimeException si está vacío o coincide con el del ERP.
     */
    public static function name(): string
    {
        $host = self::configured();

        // Vacío, `Route::domain()` no ataría nada y `/{token}` quedaría abierto en el ERP.
        if ($host === '') {
            throw new \RuntimeException('QR_HOST está vacío: define el dominio del QR de las estampitas.');
        }

        // Igual al del ERP, IsolateQrHost le daría 404 a todo el ERP.
        if ($host === self::erpHost()) {
            throw new \RuntimeException('QR_HOST no puede ser el mismo dominio de APP_URL.');
        }

        return $host;
    }

    /**
     * En un servidor el dominio no puede ser el de desarrollo: el QR no abriría en ningún celular, y el detalle de la OP,
     * el de Códigos QR y el certificado fallarían. Lo comprueba también la revisión de salud (`/up`), así que un
     * despliegue sin QR_HOST se revierte solo en vez de quedar en vivo.
     *
     * @throws \RuntimeException
     */
    public static function assertConfiguredForServer(): void
    {
        $host = self::name();

        if (DeploymentEnvironment::isProtected() && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            throw new \RuntimeException('QR_HOST no está configurado en este servidor: el QR de las estampitas no abriría.');
        }
    }

    public static function matches(Request $request): bool
    {
        return Str::lower($request->getHost()) === self::configured();
    }

    /**
     * A dónde lleva la raíz del dominio del QR. Se llama al registrar las rutas, así que un valor inválido se nota al
     * arrancar (y la revisión de salud revierte el despliegue).
     *
     * @throws \RuntimeException si no es una dirección completa: vacía, la raíz se redirigiría a sí misma sin fin.
     */
    public static function portfolioUrl(): string
    {
        $url = trim((string) config('qr.portfolio_url'));

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \RuntimeException('QR_PORTFOLIO_URL tiene que ser una dirección completa, p. ej. https://beprocoatings.com.');
        }

        return $url;
    }

    /** El dominio tal como está en la configuración, normalizado y sin validar: `name()` le agrega la validación. */
    private static function configured(): string
    {
        return Str::lower(trim((string) config('qr.host')));
    }

    private static function erpHost(): string
    {
        return Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }
}
