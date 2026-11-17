<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

// Una sesión abierta debe caer si la cuenta se desactiva o si cambia su contraseña. Cada prueba entra por el login de
// verdad y, entre una petición y otra, olvida el usuario en memoria (`forgetGuards`): así la siguiente petición lo lee
// de la sesión y de la base, como pasa con un navegador real.

function signInThroughLogin(User $user, bool $remember = false): TestResponse
{
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => $remember,
    ]);

    test()->assertAuthenticatedAs($user);

    return $response;
}

function startNextRequest(): void
{
    Auth::forgetGuards();
}

test('a user deactivated while signed in is signed out on the next request', function () {
    $user = userWithRole(SystemRole::Operator);
    signInThroughLogin($user);
    $this->get(route('dashboard'))->assertOk();

    User::query()->whereKey($user->id)->update(['is_active' => false]);
    startNextRequest();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertInertiaFlash('error', __('auth.account_inactive'));

    $this->assertGuest();
});

test('a deactivated user cannot get back in with the remember me cookie', function () {
    $user = userWithRole(SystemRole::Operator);
    $recaller = Auth::guard('web')->getRecallerName();
    $rememberCookie = signInThroughLogin($user, remember: true)->getCookie($recaller)->getValue();

    // Sin sesión, la cookie sola vuelve a abrirla: es el camino que hay que cerrar.
    $this->flushSession();
    startNextRequest();
    $this->withCookie($recaller, $rememberCookie)->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
    $tokenBefore = $user->fresh()->remember_token;

    User::query()->whereKey($user->id)->update(['is_active' => false]);
    $this->flushSession();
    startNextRequest();
    $lastLoginBefore = $user->fresh()->last_login_at;
    $auditBefore = Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->count();
    $this->travel(5)->minutes();

    $this->withCookie($recaller, $rememberCookie)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->fresh()->remember_token)->not->toBe($tokenBefore)
        // La cookie dispara el aviso de inicio de sesión, pero en una cuenta desactivada no cuenta como ingreso.
        ->and($user->fresh()->last_login_at?->toIso8601String())->toBe($lastLoginBefore?->toIso8601String())
        ->and(Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->count())
        ->toBe($auditBefore);
});

test('a deactivated user calling a JSON endpoint gets a 401 instead of the login page', function () {
    $user = userWithRole(SystemRole::Operator);
    signInThroughLogin($user);

    User::query()->whereKey($user->id)->update(['is_active' => false]);
    startNextRequest();

    $this->getJson(route('dashboard'))
        ->assertUnauthorized()
        ->assertJson(['message' => __('auth.account_inactive')]);

    $this->assertGuest();
});

test('changing the password signs out the sessions that were already open', function () {
    $user = userWithRole(SystemRole::Operator);
    signInThroughLogin($user);
    $this->get(route('dashboard'))->assertOk();

    User::query()->whereKey($user->id)->update(['password' => Hash::make('una-clave-nueva-y-larga')]);
    startNextRequest();

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('the remember me cookie stops working after the password changes', function () {
    $user = userWithRole(SystemRole::Operator);
    $recaller = Auth::guard('web')->getRecallerName();
    $rememberCookie = signInThroughLogin($user, remember: true)->getCookie($recaller)->getValue();

    User::query()->whereKey($user->id)->update(['password' => Hash::make('una-clave-nueva-y-larga')]);
    $this->flushSession();
    startNextRequest();

    $this->withCookie($recaller, $rememberCookie)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a form saved with PUT, PATCH or DELETE from a revoked session is redirected with 303', function () {
    $user = userWithRole(SystemRole::Operator);
    signInThroughLogin($user);
    $this->get(route('dashboard'))->assertOk();

    User::query()->whereKey($user->id)->update(['password' => Hash::make('una-clave-nueva-y-larga')]);
    startNextRequest();

    // Con 302 el navegador repetiría el PATCH contra /login, que solo acepta GET y POST (405). `auth.session` corre antes
    // que HandleInertiaRequests; el 303 lo pone el middleware global de Inertia (EnsureGetOnRedirect).
    $this->withHeaders(['X-Inertia' => 'true'])
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email])
        ->assertStatus(303)
        ->assertRedirect(route('login'));
});

test('an active user keeps the session', function () {
    $user = userWithRole(SystemRole::Operator);
    signInThroughLogin($user);
    startNextRequest();

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
});
