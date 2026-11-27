<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->unit = UnitOfMeasure::create([
        'code' => 'kg',
        'name' => 'Kilogramo',
        'symbol' => 'kg',
    ]);

    $this->category = ProductCategory::create([
        'name' => 'Categoría Test',
    ]);

    $this->adminUser = User::factory()->create(['email_verified_at' => now()]);
    $this->adminUser->assignRole('admin');

    $this->productionUser = User::factory()->create(['email_verified_at' => now()]);
    $this->productionUser->assignRole(SystemRole::Production->value);

    $this->comercialUser = User::factory()->create(['email_verified_at' => now()]);
    $this->comercialUser->assignRole(SystemRole::Commercial->value);

    $this->product = Product::create([
        'name' => 'Producto Test',
        'category_id' => $this->category->id,
        'unit_of_measure_id' => $this->unit->id,
        'current_cost' => 80.0000,
        'cif_percentage' => 15.00,
        'current_price' => 92.0000,
        'is_active' => true,
    ]);
});

describe('index', function () {
    it('allows admin to view costs page', function () {
        $this->actingAs($this->adminUser)
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Costs/Index')
                ->has('products.data', 1)
                ->where('products.data.0.id', $this->product->id)
                ->where('products.data.0.name', 'PRODUCTO TEST')
                ->where('products.data.0.current_cost', '80.0000')
                ->where('products.data.0.cif_percentage', '15.00')
                ->where('products.data.0.current_price', '92.0000')
                ->where('products.data.0.sales_price', 92)
                ->where('can.update_margin', true)
                ->where('can.view_prices', true)
            );
    });

    it('calculates whole-peso sales_price on server when product has margin', function () {
        $this->product->update(['sales_margin' => 25.00]);

        $this->actingAs($this->adminUser)
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.data.0.sales_margin', '25.00')
                ->where('products.data.0.sales_price', 123)
            );
    });

    it('returns null sales_price when product has no current_price', function () {
        $this->product->update(['current_price' => null, 'sales_margin' => 25.00]);

        $this->actingAs($this->adminUser)
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.data.0.sales_price', null)
            );
    });

    it('sets can.view_prices to false when user has costs permissions without price_lists.view', function () {
        $costOnlyUser = User::factory()->create(['email_verified_at' => now()]);
        $costOnlyUser->givePermissionTo([
            Permission::CostsView->value,
            Permission::CostsUpdate->value,
        ]);

        $this->actingAs($costOnlyUser)
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.update_margin', true)
                ->where('can.view_prices', false)
            );
    });

    it('forbids produccion to view costs page', function () {
        $this->actingAs($this->productionUser)
            ->get(route('admin.costs.index'))
            ->assertForbidden();
    });

    it('forbids comercial to view costs page', function () {
        $this->actingAs($this->comercialUser)
            ->get(route('admin.costs.index'))
            ->assertForbidden();
    });

    it('requires authentication', function () {
        $this->get(route('admin.costs.index'))
            ->assertRedirect(route('login'));
    });

    it('filters products by search term', function () {
        Product::create([
            'name' => 'Otro Producto',
            'category_id' => $this->category->id,
            'unit_of_measure_id' => $this->unit->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->get(route('admin.costs.index', ['search' => 'Test']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'PRODUCTO TEST')
            );
    });
});

describe('read-only route removal', function () {
    it('no longer exposes PATCH /admin/costs/{product}', function () {
        $this->actingAs($this->adminUser)
            ->patch('/admin/costs/'.$this->product->id, [
                'sales_margin' => 25.00,
                'sales_price' => 115.00,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'sales_margin' => null,
        ]);
    });
});
