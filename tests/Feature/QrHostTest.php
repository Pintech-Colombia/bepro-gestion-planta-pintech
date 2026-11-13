<?php

declare(strict_types=1);

use App\Actions\Production\BuildProductionOrderShowDataAction;
use App\Enums\Permission;
use App\Http\Middleware\IsolateQrHost;
use App\Models\ProductionOrder;
use App\Models\QrCode;
use App\Models\User;
use App\Services\TimezoneService;
use App\Support\QrHost;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;

/**
 * El dominio propio del QR de las estampitas: qué responde ahí, qué no, y cómo se arma la URL que se imprime.
 */
function qrUrl(string $path = '/'): string
{
    return 'http://'.config('qr.host').$path;
}

/** Las pruebas reutilizan el dominio de la última petición: las del ERP lo dicen explícito. */
function erpUrl(string $path = '/'): string
{
    return rtrim((string) config('app.url'), '/').$path;
}

function assertQrUnavailablePage($response, int $status, string $reason): void
{
    $response->assertStatus($status)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Public/QrLanding/Unavailable')
            ->where('reason', $reason)
            ->where('portfolioUrl', 'https://beprocoatings.com'));
}

// --- Token ---

it('generates short lowercase tokens', function () {
    foreach (range(1, 20) as $ignored) {
        expect(QrCode::newToken())->toMatch('/^[a-z0-9]{12}$/');
    }
});

it('builds the printed url with the qr host and the short token', function () {
    $qrCode = QrCode::factory()->create();

    expect($qrCode->token)->toMatch('/^[a-z0-9]{12}$/')
        ->and(parse_url($qrCode->landingUrl(), PHP_URL_HOST))->toBe('qr.localhost')
        ->and(parse_url($qrCode->landingUrl(), PHP_URL_PATH))->toBe('/'.$qrCode->token)
        ->and($qrCode->url)->toBe($qrCode->landingUrl());
});

// --- El dominio del QR ---

it('sends the root of the qr host to the portfolio, and the erp root to the login', function () {
    $this->get(qrUrl('/'))
        ->assertRedirect('https://beprocoatings.com')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get(erpUrl('/'))->assertRedirect(erpUrl('/login'));
});

it('serves the public page on the qr host with noindex', function () {
    $qrCode = QrCode::factory()->create();

    $this->get(qrUrl('/'.$qrCode->token))
        ->assertSuccessful()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Public/QrLanding/Show'));
});

it('answers nothing of the erp on the qr host', function (string $method, string $path) {
    $response = $this->call($method, qrUrl($path));

    assertQrUnavailablePage($response, 404, 'not_found');
})->with([
    'login' => ['GET', '/login'],
    'login (POST)' => ['POST', '/login'],
    'forgot-password' => ['GET', '/forgot-password'],
    'storage' => ['GET', '/storage/anything.pdf'],
    'dashboard' => ['GET', '/dashboard'],
    'health check' => ['GET', '/up'],
    'old public path' => ['GET', '/c/abcdefghijkl'],
]);

// El 404 que arma IsolateQrHost se pinta dentro del middleware: si corriera antes que TrustProxies, la app creería que la
// visita llegó por HTTP y la página cargaría CSS y JS por http dentro de https (el navegador los bloquea).
it('isolates the qr host after trusting the proxy', function () {
    $global = app(Kernel::class)->getGlobalMiddleware();
    $trustProxies = array_search(TrustProxies::class, $global, true);
    $isolateQrHost = array_search(IsolateQrHost::class, $global, true);

    expect($trustProxies)->toBeInt()
        ->and($isolateQrHost)->toBeInt()
        ->and($trustProxies)->toBeLessThan($isolateQrHost);
});

it('does not log anyone in through the qr host', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->post(qrUrl('/login'), ['email' => $user->email, 'password' => 'password'])->assertNotFound();

    $this->assertGuest();
});

it('keeps the erp working on other hosts', function () {
    // La revisión de salud de Docker llama a `/up` por localhost.
    $this->get('http://localhost/up')->assertSuccessful();
    $this->get(erpUrl('/login'))->assertSuccessful()->assertHeaderMissing('X-Robots-Tag');
});

it('does not serve the public page on the erp host', function () {
    $qrCode = QrCode::factory()->create();

    $this->get(erpUrl('/'.$qrCode->token))->assertNotFound();
    $this->get(erpUrl('/c/'.$qrCode->token))->assertNotFound();
});

it('shows the unavailable page for a method the public routes do not take', function () {
    $qrCode = QrCode::factory()->create();

    $response = $this->post(qrUrl('/'.$qrCode->token));

    assertQrUnavailablePage($response, 405, 'not_found');
    // Obligatoria en un 405: qué métodos sí acepta la ruta.
    expect($response->headers->get('Allow'))->toContain('GET');
});

it('shows the unavailable page for an unknown or inactive token', function () {
    $qrCode = QrCode::factory()->inactive()->create();

    assertQrUnavailablePage($this->get(qrUrl('/'.$qrCode->token)), 404, 'not_found');
    assertQrUnavailablePage($this->get(qrUrl('/zzzzzzzzzzzz')), 404, 'not_found');
});

it('shows the unavailable page in spanish for a server error, keeping the status', function () {
    config(['app.debug' => false]);
    $qrCode = QrCode::factory()->create();
    // Sobre una instancia real: los datos compartidos de Inertia también usan este servicio.
    $timezone = Mockery::mock(app(TimezoneService::class));
    $timezone->shouldReceive('formatPlantDate')->andThrow(new RuntimeException('falla'));
    $this->instance(TimezoneService::class, $timezone);

    assertQrUnavailablePage($this->get(qrUrl('/'.$qrCode->token)), 500, 'server_error');
});

// Si la página del QR falla al pintarse, el cliente ve la respuesta original y la falla queda en el log; nunca cae a la
// ErrorPage del ERP, cuyo botón lleva al login.
it('never falls back to the erp error page on the qr host when its own page fails', function () {
    Exceptions::fake();
    // Las rutas ya se registraron con el portafolio válido: aquí solo falla al pintar la página de error.
    config(['qr.portfolio_url' => '']);

    $response = $this->get(qrUrl('/zzzzzzzzzzzz'));

    $response->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($response->getContent())->not->toContain('ErrorPage')
        ->and($response->getContent())->not->toContain('Public/QrLanding/Unavailable');
    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'QR_PORTFOLIO_URL'));
});

it('limits the qr host to 60 requests per minute per ip', function () {
    foreach (range(1, 60) as $ignored) {
        $this->get(qrUrl('/zzzzzzzzzzzz'))->assertNotFound();
    }

    $response = $this->get(qrUrl('/zzzzzzzzzzzz'));

    assertQrUnavailablePage($response, 429, 'too_many_requests');
    $response->assertHeader('Retry-After');

    // Otra IP sigue entrando.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get(qrUrl('/'))->assertRedirect();
});

// --- Barreras del dominio ---

it('refuses an empty qr host or the erp host', function (string $host) {
    config(['qr.host' => $host, 'app.url' => 'https://staging.example.com']);

    QrHost::name();
})->with(['empty' => '', 'erp host' => 'staging.example.com'])->throws(RuntimeException::class);

// Vacío, la raíz del dominio del QR se redirigiría a sí misma sin fin.
it('refuses a portfolio url that is not a full address', function (string $url) {
    config(['qr.portfolio_url' => $url]);

    QrHost::portfolioUrl();
})->with(['empty' => '', 'without scheme' => 'beprocoatings.com', 'other scheme' => 'ftp://beprocoatings.com'])
    ->throws(RuntimeException::class, 'QR_PORTFOLIO_URL');

it('refuses to build a printed url to localhost on a server', function () {
    $qrCode = QrCode::factory()->create();
    app()->detectEnvironment(fn () => 'staging');

    $qrCode->landingUrl();
})->throws(RuntimeException::class, 'QR_HOST no está configurado');

// Sin QR_HOST, el detalle de la OP, el de Códigos QR y el certificado también fallarían: el despliegue tiene que
// revertirse solo (pintech-deploy revisa `/up`).
it('fails the health check on a server without the qr host', function () {
    config(['app.debug' => false]);
    app()->detectEnvironment(fn () => 'staging');

    $this->get('http://localhost/up')->assertStatus(500);
});

it('passes the health check on a server with the qr host', function () {
    config(['app.debug' => false, 'qr.host' => 't.example.com']);
    app()->detectEnvironment(fn () => 'staging');

    $this->get('http://localhost/up')->assertSuccessful();
});

it('builds the printed url to localhost outside a server', function () {
    $url = QrCode::landingUrlFor('abcdefghijkl');

    expect(parse_url($url, PHP_URL_HOST))->toBe('qr.localhost')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/abcdefghijkl');
});

// --- La tarjeta de la OP ---

it('gives the order card the absolute public url and an erp image url', function () {
    $qrCode = QrCode::factory()->create();

    $data = app(BuildProductionOrderShowDataAction::class)->execute($qrCode->productionOrder->fresh(), false);

    expect($data['qr_landing_url'])->toBe($qrCode->landingUrl())
        ->and($data['qr_image_url'])->toBe('/production-orders/'.$qrCode->production_order_id.'/qr.png');
});

it('lets whoever sees the order download its qr image, without the qr codes permission', function () {
    $qrCode = QrCode::factory()->create();
    $user = userWithPermissions([Permission::ProductionOrdersView]);

    expect($user->can(Permission::QrCodesView->value))->toBeFalse();

    $response = $this->actingAs($user)->get(route('production-orders.qr-image', $qrCode->production_order_id));

    $response->assertSuccessful()->assertHeader('Content-Type', 'image/png');
    // Sin caché: tras un cambio de dominio, la descarga no puede salir con el QR viejo.
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->and($response->getContent())->toStartWith("\x89PNG");
});

it('denies the qr image to whoever cannot see the order', function () {
    $qrCode = QrCode::factory()->create();

    $this->actingAs(userWithPermissions([Permission::QrCodesView]))
        ->get(route('production-orders.qr-image', $qrCode->production_order_id))
        ->assertForbidden();
});

it('has no qr image for an order without an active qr', function () {
    $user = userWithPermissions([Permission::ProductionOrdersView]);
    $inactive = QrCode::factory()->inactive()->create();
    $withoutQr = ProductionOrder::factory()->create();

    $this->actingAs($user)->get(route('production-orders.qr-image', $inactive->production_order_id))->assertNotFound();
    $this->actingAs($user)->get(route('production-orders.qr-image', $withoutQr))->assertNotFound();
});

// --- Migración de tokens cortos ---

it('moves the existing long tokens to short ones with the new url', function () {
    $long = QrCode::factory()->create();
    $short = QrCode::factory()->create();
    DB::table('qr_codes')->where('id', $long->id)->update(['token' => str_repeat('AbC1', 10), 'url' => 'https://old/c/x']);

    $migration = require database_path('migrations/2026_11_13_100000_shorten_qr_code_tokens.php');
    $migration->up();

    $long->refresh();
    expect($long->token)->toMatch('/^[a-z0-9]{12}$/')
        ->and($long->url)->toBe($long->landingUrl())
        ->and($short->fresh()->only(['token', 'url']))->toBe($short->only(['token', 'url']));
});

// pintech-deploy migra antes de cambiar contenedores: si la migración falla, el despliegue se aborta sin tocar datos.
it('refuses to move the tokens on a server without the qr host, before touching a row', function () {
    $qrCode = QrCode::factory()->create();
    $longToken = str_repeat('AbC1', 10);
    DB::table('qr_codes')->where('id', $qrCode->id)->update(['token' => $longToken]);
    app()->detectEnvironment(fn () => 'staging');

    $migration = require database_path('migrations/2026_11_13_100000_shorten_qr_code_tokens.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'QR_HOST no está configurado')
        ->and(DB::table('qr_codes')->where('id', $qrCode->id)->value('token'))->toBe($longToken);
});
