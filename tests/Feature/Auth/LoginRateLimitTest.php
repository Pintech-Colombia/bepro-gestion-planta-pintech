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
        attemptLoginFrom('203.0.113.7', "probe{$attempt}@example.test")->assertSessionHasErrors('email');
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
        attemptLoginFrom('203.0.113.7', $user->email)->assertSessionHasErrors('email');
    }

    attemptLoginFrom('203.0.113.7', $user->email)->assertTooManyRequests();

    // El correo escrito con otras mayúsculas cuenta como el mismo.
    attemptLoginFrom('203.0.113.7', strtoupper($user->email))->assertTooManyRequests();
});

it('frena en el intento 11 un correo atacado desde muchas IP', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 10) as $attempt) {
        attemptLoginFrom("198.51.100.{$attempt}", $user->email)->assertSessionHasErrors('email');
    }

    // El intento 11, con el correo en mayúsculas y desde una IP nueva: cuenta como el mismo correo.
    attemptLoginFrom('198.51.100.11', strtoupper($user->email))->assertTooManyRequests();
    attemptLoginFrom('198.51.100.12', $user->email)->assertTooManyRequests();

    // Los demás correos siguen entrando desde esas IP.
    $other = User::factory()->create();
    attemptLoginFrom('198.51.100.1', $other->email, 'password')
        ->assertRedirect(route('dashboard', absolute: false));
});

it('levanta el bloqueo al pasar un minuto', function (array $attempts, array $blocked): void {
    foreach ($attempts as [$ip, $email]) {
        attemptLoginFrom($ip, $email)->assertSessionHasErrors('email');
    }

    attemptLoginFrom(...$blocked)->assertTooManyRequests();

    $this->travel(59)->seconds();
    attemptLoginFrom(...$blocked)->assertTooManyRequests();

    $this->travel(2)->seconds();
    attemptLoginFrom(...$blocked)->assertSessionHasErrors('email');
})->with([
    'correo + IP (5)' => [
        array_fill(0, 5, ['203.0.113.7', 'victim@example.test']),
        ['203.0.113.7', 'victim@example.test'],
    ],
    'IP (20)' => [
        array_map(fn (int $n): array => ['203.0.113.7', "probe{$n}@example.test"], range(1, 20)),
        ['203.0.113.7', 'probe21@example.test'],
    ],
    'correo (10)' => [
        array_map(fn (int $n): array => ["198.51.100.{$n}", 'victim@example.test'], range(1, 10)),
        ['198.51.100.11', 'victim@example.test'],
    ],
]);

it('cuenta como una sola IP las direcciones IPv6 del mismo bloque /64', function (): void {
    // Quien tiene un /64 puede estrenar dirección en cada intento.
    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom("2001:db8:abcd:12::{$attempt}", "probe{$attempt}@example.test")
            ->assertSessionHasErrors('email');
    }

    attemptLoginFrom('2001:db8:abcd:12:ffff:ffff:ffff:ffff', 'probe21@example.test')->assertTooManyRequests();
});

it('cuenta el mismo correo desde un bloque /64 como un solo equipo', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        attemptLoginFrom("2001:db8:abcd:12::{$attempt}", $user->email)->assertSessionHasErrors('email');
    }

    attemptLoginFrom('2001:db8:abcd:12::6', $user->email)->assertTooManyRequests();
});

it('cuenta aparte las direcciones IPv6 de bloques /64 distintos', function (): void {
    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom("2001:db8:abcd:12::{$attempt}", "probe{$attempt}@example.test");
    }

    attemptLoginFrom('2001:db8:abcd:13::1', 'probe21@example.test')->assertSessionHasErrors('email');
});

it('sigue contando los intentos cuando la petición llega sin IP', function (): void {
    // Detrás de Nginx siempre hay IP; sin ella el login no debe responder 500. Sin REMOTE_ADDR (null) no se prueba:
    // Symfony ya falla al preparar cualquier respuesta (Request::isFromTrustedProxy), antes de llegar al limitador.
    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom('', "probe{$attempt}@example.test")->assertSessionHasErrors('email');
    }

    attemptLoginFrom('', 'probe21@example.test')->assertTooManyRequests();
});

it('avisa en español cuando el login queda frenado', function (): void {
    foreach (range(1, 20) as $attempt) {
        attemptLoginFrom('203.0.113.7', "probe{$attempt}@example.test", inertia: true);
    }

    attemptLoginFrom('203.0.113.7', 'probe21@example.test', inertia: true)
        ->assertRedirect(route('login'))
        ->assertInertiaFlash('error', 'Demasiados intentos de inicio de sesión. Inténtalo de nuevo en 60 segundos.');
});
