<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * La página de error del dominio del QR. Ahí llega un cliente que escaneó una estampita, no un usuario del ERP: nunca
 * `ErrorPage`, cuyo botón lleva al login, que en ese dominio no existe. Una página propia en español con enlace al
 * portafolio, con el mismo código de estado. La llama el manejador de errores de bootstrap/app.php antes que el del ERP.
 */
final class QrHostErrorPage
{
    /** Las cabeceras que el código de estado exige o sugiere: cuándo volver a intentar (429) y qué métodos acepta (405). */
    private const COPIED_HEADERS = ['Retry-After', 'Allow'];

    /**
     * `null` solo cuando no aplica (otro dominio o un estado que no maneja): sigue el manejo del ERP. Si aplica pero la
     * página falla al pintarse, la respuesta original: nunca cae al bloque del ERP en el dominio del QR.
     */
    public static function for(SymfonyResponse $response, Request $request): ?SymfonyResponse
    {
        $status = $response->getStatusCode();
        $reason = self::reason($status);

        if ($reason === null || ! QrHost::matches($request)) {
            return null;
        }

        try {
            $page = Inertia::render('Public/QrLanding/Unavailable', [
                'reason' => $reason,
                'portfolioUrl' => QrHost::portfolioUrl(),
            ])->toResponse($request)->setStatusCode($status);

            foreach (self::COPIED_HEADERS as $header) {
                if ($response->headers->has($header)) {
                    $page->headers->set($header, (string) $response->headers->get($header));
                }
            }

            return $page;
        } catch (Throwable $renderFailure) {
            // El cliente ve la respuesta original, pero la falla queda en el log.
            report($renderFailure);

            return $response;
        }
    }

    /**
     * El motivo que muestra la página (`Public/QrLanding/Unavailable`). Un 500 con APP_DEBUG activo no aplica: conserva la
     * traza de Laravel, como en el ERP.
     */
    private static function reason(int $status): ?string
    {
        return match (true) {
            // 405: un POST a `/{token}`. Para el cliente es lo mismo: ese enlace no lleva a un lote.
            in_array($status, [403, 404, 405], true) => 'not_found',
            $status === 429 => 'too_many_requests',
            $status >= 500 && ! config('app.debug') => 'server_error',
            default => null,
        };
    }
}
