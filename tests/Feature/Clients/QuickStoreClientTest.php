<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\QuotationStatus;
use App\Enums\SystemRole;
use App\Models\Client;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->commercial = User::factory()->create();
    $this->commercial->assignRole(SystemRole::Commercial->value);
});

it('creates a client from the quotation and answers with it in JSON', function () {
    $this->actingAs($this->commercial)
        ->postJson(route('clients.quick-store'), [
            'business_name' => 'Pinturas del Valle S.A.S.',
            'nit' => '900  123 456-7',
            'contact_name' => 'Ana Pérez',
            'phone' => '3001234567',
            'shipping_address' => 'Calle 10 # 45-67, Yumbo',
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'business_name', 'nit', 'contact_name', 'phone', 'shipping_address'])
        ->assertJson([
            'business_name' => 'Pinturas del Valle S.A.S.',
            // Las mismas reglas de la página de clientes: el NIT se guarda con los espacios colapsados.
            'nit' => '900 123 456-7',
            'contact_name' => 'Ana Pérez',
        ]);

    expect(Client::where('business_name', 'Pinturas del Valle S.A.S.')->exists())->toBeTrue();
});

it('records the new client in the activity log, like the clients page', function () {
    $this->actingAs($this->commercial)
        ->postJson(route('clients.quick-store'), ['business_name' => 'Cliente auditado'])
        ->assertCreated();

    $client = Client::where('business_name', 'Cliente auditado')->firstOrFail();

    expect(Activity::where('log_name', 'clientes')
        ->where('subject_type', $client->getMorphClass())
        ->where('subject_id', $client->id)
        ->where('causer_id', $this->commercial->id)
        ->exists())->toBeTrue();
});

it('rejects a repeated NIT with the same message as the clients page', function () {
    Client::factory()->create(['nit' => '900123456']);

    $this->actingAs($this->commercial)
        ->postJson(route('clients.quick-store'), [
            'business_name' => 'Otro cliente',
            'nit' => '900123456',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nit']);

    expect(Client::where('business_name', 'Otro cliente')->exists())->toBeFalse();
});

it('requires the business name', function () {
    $this->actingAs($this->commercial)
        ->postJson(route('clients.quick-store'), ['business_name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['business_name']);
});

it('forbids a user without clients.create', function () {
    $production = User::factory()->create();
    $production->assignRole(SystemRole::Production->value);

    $this->actingAs($production)
        ->postJson(route('clients.quick-store'), ['business_name' => 'No debería existir'])
        ->assertForbidden();

    expect(Client::where('business_name', 'No debería existir')->exists())->toBeFalse();
});

it('tells the quotation form whether the user can create clients', function () {
    $this->actingAs($this->commercial)
        ->get(route('quotations.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Quotations/Create')
            ->where('can.createClient', true)
        );
});

it('tells the quotation edit form whether the user can create clients', function () {
    $quotation = Quotation::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'created_by' => $this->commercial->id,
        'status' => QuotationStatus::Draft,
    ]);

    $this->actingAs($this->commercial)
        ->get(route('quotations.edit', $quotation))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Quotations/Edit')
            ->where('can.createClient', true)
        );
});

it('hides «Nuevo cliente» from a user who can quote but cannot create clients', function () {
    // Un rol a la medida: cotiza, pero no crea clientes.
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::QuotationsCreate->value);

    $this->actingAs($user)
        ->get(route('quotations.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Quotations/Create')
            ->where('can.createClient', false)
        );
});
