<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\Auth\PasswordResetLinkController as CustomPasswordResetLinkController;
use App\Models\User;
use App\Support\DeploymentEnvironment;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Symfony\Component\HttpFoundation\IpUtils;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PasswordResetLinkController::class, CustomPasswordResetLinkController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRequirements' => DeploymentEnvironment::passwordRequirements(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Tres límites a la vez; frena el primero que se agote. Cada uno con su prefijo para que los contadores no se
        // mezclen. La IP es la real del visitante (CF-Connecting-IP, ver bootstrap/app.php).
        // El límite corre ANTES que `guest`, aunque `route:list` muestre lo contrario (ese es el orden declarado):
        // Laravel sube ThrottleRequests por prioridad. Así que un envío del login con la sesión ya abierta también
        // cuenta; es raro y se aceptó. Orden real: app('router')->gatherRouteMiddleware() sobre `login.store`, con el
        // kernel HTTP cargado.
        RateLimiter::for('login', function (Request $request) {
            // El limitador corre antes de validar: un arreglo en el correo (petición armada a mano) no es texto.
            $username = $request->input(Fortify::username());
            $email = Str::transliterate(Str::lower(is_string($username) ? $username : ''));
            $ip = $this->loginThrottleIp($request);

            return [
                // Muchos intentos sobre una cuenta desde un mismo equipo.
                Limit::perMinute(5)->by('email-ip:'.$email.'|'.$ip),
                // Una IP que prueba muchos correos (rociado de contraseñas). La planta sale por una sola IP y sus
                // usuarios no llegan a 20 ingresos por minuto.
                Limit::perMinute(20)->by('ip:'.$ip),
                // Una cuenta atacada desde muchas IP. Contra: quien conoce el correo puede mantener frenada esa cuenta
                // mientras siga intentando desde 2 o más IP (los intentos frenados no suman, así que le bastan 10 por
                // minuto), y con N/2 IP frena N cuentas a la vez (cada IP da 20 por minuto, 5 por correo). Respuesta:
                // bloquear esas IP en Cloudflare. Caso límite aceptado (CL-68).
                Limit::perMinute(10)->by('email:'.$email),
            ];
        });
    }

    /**
     * La IP con la que cuentan los límites del login. Una IPv6 cuenta por su bloque /64: el proveedor le entrega a cada
     * cliente al menos un /64 y quien lo tiene puede estrenar dirección en cada intento. Las IPv4, también las mapeadas
     * (::ffff:x.x.x.x), quedan completas. Sin una IP válida (no pasa detrás de Nginx) se usa tal cual: anonymize()
     * lanzaría un TypeError y el login respondería 500.
     */
    private function loginThrottleIp(Request $request): string
    {
        $ip = (string) $request->ip();

        return filter_var($ip, FILTER_VALIDATE_IP) === false ? $ip : IpUtils::anonymize($ip, 0, 8);
    }

    /**
     * Configure authentication behaviors.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return null;
            }

            // Mensaje diferenciado intencional.
            // No aplica riesgo de enumeración en contexto cerrado sin registro público.
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('auth.account_inactive'),
                ]);
            }

            return $user;
        });
    }
}
