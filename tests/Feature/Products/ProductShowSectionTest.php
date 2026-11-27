<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Formula;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Pestaña activa de la ficha de producto (`?section=`): el servidor la valida y la devuelve como prop.

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->product = Product::factory()->create(['name' => 'Esmalte pestañas']);

    // Ve el producto, pero no sus fórmulas ni tiene permisos extra de descarga/inventario/creación.
    $role = Role::create(['name' => 'catalogo-solo-lectura', 'guard_name' => 'web']);
    $role->syncPermissions([Permission::ProductsView->value]);

    $this->userReadOnly = User::factory()->create();
    $this->userReadOnly->assignRole($role);
});

it('forbids users without products.view', function (): void {
    $roleWithoutProducts = Role::create(['name' => 'sin-productos', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleWithoutProducts);

    $this->actingAs($user)
        ->get(route('products.show', $this->product))
        ->assertForbidden();
});

it('opens the formulas tab for a user who can view formulas', function (): void {
    $this->actingAs(userWithRole(SystemRole::Admin))
        ->get(route('products.show', ['product' => $this->product, 'section' => 'formulas']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('can.viewFormulas', true)
            ->where('section', 'formulas'));
});

it('opens the quality tab and preserves return_to', function (): void {
    $returnTo = '/products?search=esmalte';

    $this->actingAs(userWithRole(SystemRole::Admin))
        ->get(route('products.show', [
            'product' => $this->product,
            'section' => 'quality',
            'return_to' => $returnTo,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('section', 'quality')
            ->where('returnTo', $returnTo));
});

it('opens presentations when the user cannot view formulas', function (): void {
    $this->actingAs($this->userReadOnly)
        ->get(route('products.show', ['product' => $this->product, 'section' => 'formulas']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewFormulas', false)
            ->where('section', 'presentations')
            ->missing('product.formulas'));
});

it('opens presentations by default and for any unknown, long, or non-string section without validation errors', function (mixed $section): void {
    $query = ['product' => $this->product->id];

    if ($section !== null) {
        $query['section'] = $section;
    }

    $this->actingAs(userWithRole(SystemRole::Admin))
        ->get(route('products.show', $query))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('section', 'presentations'));
})->with([
    'sin parámetro' => [null],
    'valor desconocido' => ['xyz'],
    'cadena mayor a 20 caracteres' => ['seccion_desconocida_demasiado_larga'],
    'arreglo en la query' => [['formulas']],
]);

it('exposes granular permissions for finished inventory, creating formulas, and downloading documents', function (): void {
    $this->actingAs(userWithRole(SystemRole::Admin))
        ->get(route('products.show', $this->product))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewFinishedInventory', true)
            ->where('can.createFormulas', true)
            ->where('can.downloadDocuments', true));

    $this->actingAs($this->userReadOnly)
        ->get(route('products.show', $this->product))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewFinishedInventory', false)
            ->where('can.createFormulas', false)
            ->where('can.downloadDocuments', false)
            ->where('units', [])
            ->where('rawMaterials', [])
            ->where('labelMaterials', []));
});

it('sends hasPriceReferenceFormula from the server even when the user cannot view formulas', function (): void {
    Formula::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'is_price_reference' => true,
    ]);

    $this->actingAs($this->userReadOnly)
        ->get(route('products.show', $this->product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewFormulas', false)
            ->where('hasPriceReferenceFormula', true)
            ->missing('product.formulas'));
});

it('skips heavy product queries during a section-only partial reload', function (): void {
    $admin = userWithRole(SystemRole::Admin);

    $this->actingAs($admin)
        ->get(route('products.show', ['product' => $this->product, 'section' => 'quality']))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $page->reloadOnly('section', fn (Assert $reload) => $reload
                ->where('section', 'quality')
                ->missing('product')
                ->missing('units')
                ->missing('rawMaterials')
                ->missing('labelMaterials'));

            $executedSql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
            DB::disableQueryLog();

            expect($executedSql)
                ->not->toContain('product_variants')
                ->not->toContain('product_documents')
                ->not->toContain('formulas')
                ->not->toContain('raw_materials')
                ->not->toContain('unit_of_measures');
        });
});
