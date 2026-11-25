<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;

// El login tiene tres límites por minuto (FortifyServiceProvider): 5 por correo + IP, 20 por IP con cualquier correo
// y 10 por correo desde cualquier IP. La IP llega en REMOTE_ADDR (el Nginx de la imagen la toma de CF-Connecting-IP).

function attemptLoginFrom(string $ip, string $email, string $password = 'wrong-password', bool $inertia = false): TestResponse
{
    $request = test()->withServerVariables(['REMOTE_ADDR' => $ip]);

    if ($inertia) {
        $request = $request->from(route('login'))->withHeader('X-Inertia', 'true');
    }

    return $request->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

it('frena en el intento 21 a una IP que prueba muchos correos distintos', function (): void {
    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom('203.0.113.7', "probe{$attempt}@example.test")->assertRedirect();
    }

    attemptLoginFrom('203.0.113.7', 'probe21@example.test')->assertTooManyRequests();
});

it('deja entrar al usuario desde otra IP mientras la IP del ataque está frenada', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom('203.0.113.7', "probe{$attempt}@example.test");
    }

    // Desde la IP frenada no entra nadie, ni con la contraseña correcta.
    attemptLoginFrom('203.0.113.7', $user->email, 'password')->assertTooManyRequests();
    $this->assertGuest();

    attemptLoginFrom('198.51.100.20', $user->email, 'password')
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('sigue frenando el sexto intento del mismo correo desde la misma IP', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        attemptLoginFrom('203.0.113.7', $user->email)->assertRedirect();
    }

    attemptLoginFrom('203.0.113.7', $user->email)->assertTooManyRequests();

    // El correo escrito con otras mayúsculas cuenta como el mismo.
    attemptLoginFrom('203.0.113.7', strtoupper($user->email))->assertTooManyRequests();
});

it('frena en el intento 11 un correo atacado desde muchas IP', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 10) as $attempt) {
        attemptLoginFrom("198.51.100.{$attempt}", $user->email)->assertRedirect();
    }

    attemptLoginFrom('198.51.100.11', $user->email)->assertTooManyRequests();

    // Los demás correos siguen entrando desde esas IP.
    $other = User::factory()->create();
    attemptLoginFrom('198.51.100.1', $other->email, 'password')
        ->assertRedirect(route('dashboard', absolute: false));
});

it('avisa en español cuando el login queda frenado', function (): void {
    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom('203.0.113.7', "probe{$attempt}@example.test", inertia: true);
    }

    attemptLoginFrom('203.0.113.7', 'probe21@example.test', inertia: true)
        ->assertRedirect(route('login'))
        ->assertInertiaFlash('error', 'Demasiados intentos de inicio de sesión. Inténtalo de nuevo en 60 segundos.');
});
