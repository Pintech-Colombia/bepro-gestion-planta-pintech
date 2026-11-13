<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\QrHost;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * En el dominio del QR solo responden las rutas atadas a él (routes/public.php). `Route::domain()` no basta: las rutas
 * sin dominio (el login, el restablecimiento de contraseña, `/storage`, `/up` y el resto del ERP) responden en
 * cualquiera. Es global porque tiene que decidir antes de que corra la ruta: con la ruta ya elegida, un POST al login
 * ya habría intentado entrar.
 *
 * Los demás dominios pasan sin cambios: la revisión de salud de Docker llama a `/up` por `localhost`.
 */
class IsolateQrHost
{
    public function __construct(
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! QrHost::matches($request)) {
            return $next($request);
        }

        $response = $this->belongsToQrHost($request)
            ? $next($request)
            // Se arma aquí y no con abort(): así este 404 también lleva la cabecera de abajo.
            : $this->exceptions->render($request, new NotFoundHttpException);

        // Los tokens son la llave del lote: no deben quedar en buscadores.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Si la URL lleva a una ruta atada al dominio del QR. Busca la ruta con la misma tabla que usará el router, así que
     * las rutas permitidas salen de un solo sitio.
     */
    private function belongsToQrHost(Request $request): bool
    {
        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (HttpException) {
            // Ninguna ruta, o el método no es el de la ruta: el router dará la misma respuesta.
            return true;
        }

        return $route->getDomain() !== null && $route->getDomain() === QrHost::name();
    }
}
