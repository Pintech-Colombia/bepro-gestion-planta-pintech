<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IsolateQrHost;
use App\Http\Middleware\StripControlCharacters;
use App\Support\QrHostErrorPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // public.php primero: la primera ruta que coincide gana, y la raíz del dominio del QR no debe caer en la
        // redirección al login de web.php.
        web: [__DIR__.'/../routes/public.php', __DIR__.'/../routes/web.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En el servidor solo llega tráfico por el túnel de Cloudflare (no hay puertos publicados). El Nginx de la imagen
        // ya reemplaza la IP de origen por la del visitante, así que Laravel ve en REMOTE_ADDR una IP pública, no la de un
        // contenedor: por eso se confía en cualquier origen ('*'). Limitarlo a las redes de Docker dejaría de reconocer
        // X-Forwarded-Proto y la app creería que la visita llegó por HTTP.
        // De las cabeceras X-Forwarded-* solo se acepta Proto (HTTPS):
        // - For (IP), no: Cloudflare le agrega la IP real al final de la que mande el visitante, y la app se quedaría con
        //   la inventada (límites de peticiones y del login, y la IP de la auditoría). La IP real ya llega en
        //   REMOTE_ADDR: el Nginx de la imagen la toma de CF-Connecting-IP, que Cloudflare siempre sobrescribe
        //   (TRUSTED_PROXY=cloudflare en el Dockerfile de servidor).
        // - Host, Port y Prefix, no: las puede mandar cualquier visitante y, por ejemplo, un enlace para restablecer la
        //   contraseña apuntaría a otro dominio.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_PROTO,
        );

        // Global, para decidir por dominio y por ruta qué responde en el dominio del QR antes de que corra la ruta. Al
        // final de la pila y no al principio: después de TrustProxies, o el 404 que arma él mismo creería que la visita
        // llegó por HTTP y la página saldría con CSS y JS por http dentro de https (el navegador los bloquea).
        $middleware->append(IsolateQrHost::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Si cambia la contraseña de una cuenta (hoy, por «¿Olvidaste tu contraseña?»), sus otras sesiones abiertas y su
        // cookie de «Recordarme» dejan de servir en la siguiente petición: quien robó la cuenta queda fuera.
        $middleware->authenticateSessions();

        $middleware->web(append: [
            StripControlCharacters::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            // En el dominio del QR, su propia página de error (antes que la del ERP, cuyo botón lleva al login).
            if ($qrPage = QrHostErrorPage::for($response, $request)) {
                return $qrPage;
            }

            if (
                $response->getStatusCode() === 429 &&
                $request->header('X-Inertia')
            ) {
                Inertia::flash('error', __('auth.throttle', ['seconds' => 60]));

                return back();
            }

            // Páginas de error con Inertia. 500 y 503 conservan la traza de Laravel mientras APP_DEBUG está activo.
            // 419: la sesión o el token CSRF expiraron. Un `back()` perdería el aviso: si la sesión expiró, el usuario ya
            // no está autenticado y la página anterior redirige al login, que consume el mensaje flash.
            $rendersErrorPage = in_array($status, [403, 404, 419], true)
                || (in_array($status, [500, 503], true) && ! config('app.debug'));

            if ($rendersErrorPage && ($request->header('X-Inertia') || ! $request->expectsJson())) {
                try {
                    return Inertia::render('ErrorPage', ['status' => $status])
                        ->toResponse($request)
                        ->setStatusCode($status);
                } catch (Throwable $renderFailure) {
                    // Si la página de error también falla, se entrega la respuesta original de Laravel y la falla queda
                    // en el log.
                    report($renderFailure);

                    return $response;
                }
            }

            return $response;
        });
    })->create();
