<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

// Detrás del túnel de Cloudflare, el Nginx de la imagen entrega a PHP la IP real del visitante en REMOTE_ADDR (la toma
// de CF-Connecting-IP) y el protocolo en X-Forwarded-Proto. Solo se confía en el protocolo. La IP no se lee de
// X-Forwarded-For: Cloudflare le agrega la real al final de la que mande el visitante. El dominio y el puerto, que
// puede inventar cualquier visitante, tampoco; el dominio sale de la cabecera Host, como la entrega Cloudflare (sin
// puerto).

beforeEach(function (): void {
    Route::get('/__proxy-probe', fn (Request $request) => [
        'secure' => $request->isSecure(),
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'port' => $request->getPort(),
        'url' => url('/reset-password/token'),
    ]);
});

it('recognizes HTTPS and takes the visitor IP from nginx, not from X-Forwarded-For', function (): void {
    // Así llega una visita con una X-Forwarded-For inventada: Cloudflare agrega la IP real al final.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '198.51.100.9, 203.0.113.7',
        ])
        ->getJson('http://erp.example.test/__proxy-probe')
        ->assertOk()
        ->assertJson([
            'secure' => true,
            'ip' => '203.0.113.7',
            'port' => 443,
        ]);
});

it('keeps limiting login attempts when the visitor invents a different X-Forwarded-For each time', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['X-Forwarded-For' => "198.51.100.{$attempt}"])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.6'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('audits a failed login with the IP from nginx, not the invented one', function (): void {
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.9'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    // `sole()`: un intento, un registro. El listener estuvo registrado dos veces (a mano y por descubrimiento).
    $activity = Activity::query()->where('event', 'failed_login')->sole();

    expect($activity->getExtraProperty('ip'))->toBe('203.0.113.7');
});

it('ignores a forwarded host, port or prefix sent by the visitor', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Prefix' => '/attacker',
        ])
        ->getJson('http://erp.example.test/__proxy-probe')
        ->assertOk();

    expect($response->json('host'))->toBe('erp.example.test')
        ->and($response->json('port'))->toBe(443)
        ->and($response->json('url'))->toBe('https://erp.example.test/reset-password/token');
});
