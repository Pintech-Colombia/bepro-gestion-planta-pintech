<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saca de la app, en su siguiente petición, a un usuario que se desactivó con la sesión abierta. Fortify solo revisa
 * `is_active` al iniciar sesión: sin esto, desactivar a alguien (por ejemplo, una cuenta robada) no lo sacaba, y la
 * cookie de «Recordarme» lo volvía a dejar entrar al vencer la sesión.
 *
 * `logout()` también cambia el `remember_token`, así que esa cookie deja de servir. Va en el grupo `web`, después de
 * `HandleInertiaRequests`, para que una petición PUT, PATCH o DELETE reciba la redirección como 303.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Igual que `auth` de Laravel: a un `fetch` que espera JSON (las vistas previas de precio y costo), un 401 en vez
        // de la página del login.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('auth.account_inactive')], 401);
        }

        Inertia::flash('error', __('auth.account_inactive'));

        return redirect()->route('login');
    }
}
