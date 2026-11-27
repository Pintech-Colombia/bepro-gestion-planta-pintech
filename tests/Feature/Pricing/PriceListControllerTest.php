<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->unit = UnitOfMeasure::create([
        'code' => 'gl',
        'name' => 'Galón',
        'symbol' => 'gl',
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
        'sales_margin' => 25.00,
        'is_active' => true,
    ]);

    $rmCategory = RawMaterialCategory::create([
        'name' => 'Envase Metal',
        'is_active' => true,
    ]);

    $rawMaterial = RawMaterial::create([
        'code' => 'ENV-001',
        'category_id' => $rmCategory->id,
        'unit_of_measure_id' => $this->unit->id,
        'current_price' => 5.00,
        'minimum_stock' => 1,
        'alert_days_before_expiry' => 30,
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'code' => 'VAR-001',
        'unit_of_measure_id' => $this->unit->id,
        'presentation_value' => 5.00,
        'presentation_label' => 'Cubeta',
        'current_cost' => 400.0000,
        'current_price' => 460.0000,
        'package_raw_material_id' => $rawMaterial->id,
        'is_active' => true,
    ]);
});

it('allows admin to view price list with variants and sales prices', function () {
    $this->actingAs($this->adminUser)
        ->get(route('prices.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Prices/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $this->product->id)
            ->where('products.data.0.name', 'PRODUCTO TEST')
            ->where('products.data.0.sales_margin', '25.00')
            // 92 / 0.75 = 122.6667 → 123 (redondea hacia arriba)
            ->where('products.data.0.sales_price', 123)
            ->has('products.data.0.variants', 1)
            ->where('products.data.0.variants.0.id', $this->variant->id)
            ->where('products.data.0.variants.0.presentation_label', 'Cubeta')
            ->missing('products.data.0.variants.0.name')
            // 460 / 0.75 = 613.3333 → 613 (redondea hacia abajo)
            ->where('products.data.0.variants.0.sales_price', 613)
            ->where('can.view_costs', true)
            ->where('can.view_prices', true)
            ->where('can.update_margin', true)
        );
});

it('allows comercial to view price list without cost data', function () {
    $this->actingAs($this->comercialUser)
        ->get(route('prices.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Prices/Index')
            ->has('products.data', 1)
            ->where('can.view_costs', false)
            ->where('can.view_prices', true)
            ->where('can.update_margin', false)
            ->where('products.data.0.id', $this->product->id)
            ->where('products.data.0.sales_price', 123)
            // Con precio de venta y margen se despeja el precio interno: el margen es costo.
            ->missing('products.data.0.sales_margin')
            ->has('products.data.0.variants', 1)
            ->where('products.data.0.variants.0.sales_price', 613)
        );
});

it('forbids produccion to view price list', function () {
    $this->actingAs($this->productionUser)
        ->get(route('prices.index'))
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->get(route('prices.index'))
        ->assertRedirect(route('login'));
});

it('calculates sales_price correctly when sales_margin is null', function () {
    $this->product->update(['sales_margin' => null]);

    $this->actingAs($this->adminUser)
        ->get(route('prices.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.sales_price', 92)
            ->where('products.data.0.variants.0.sales_price', 460)
        );
});

it('filters by search term', function () {
    Product::create([
        'name' => 'Otro Producto',
        'category_id' => $this->category->id,
        'unit_of_measure_id' => $this->unit->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('prices.index', ['search' => 'Cubeta']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $this->product->id)
        );
});

describe('updateMargin', function () {
    it('allows admin to update sales_margin and audits the change', function () {
        $this->actingAs($this->adminUser)
            ->patch(route('prices.margin.update', $this->product), [
                'sales_margin' => '29.32',
            ])
            ->assertRedirect()
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'sales_margin' => '29.32',
        ]);

        $activity = Activity::query()
            ->where('subject_type', Product::class)
            ->where('subject_id', $this->product->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->properties['old']['sales_margin'] ?? null)->toBe('25.00');
        expect($activity->properties['attributes']['sales_margin'] ?? null)->toBe('29.32');
    });

    it('keeps the exact same whole-peso price across reloads after saving margin', function () {
        $this->product->update(['current_price' => '1766902.5000']);
        $this->variant->update(['current_price' => '1766902.5000']);

        $this->actingAs($this->adminUser)
            ->patch(route('prices.margin.update', $this->product), [
                'sales_margin' => '29.32',
            ])
            ->assertRedirect();

        // 1766902.5 / (1 - 0.2932) = 2499862.0543... → 2499862 en lecturas repetidas.
        foreach ([1, 2] as $_attempt) {
            $this->actingAs($this->adminUser)
                ->get(route('prices.index'))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('products.data.0.sales_margin', '29.32')
                    ->where('products.data.0.sales_price', 2499862)
                    ->where('products.data.0.variants.0.sales_price', 2499862)
                );
        }
    });

    it('allows admin to clear sales_margin to null', function () {
        $this->actingAs($this->adminUser)
            ->patch(route('prices.margin.update', $this->product), [
                'sales_margin' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'sales_margin' => null,
        ]);
    });

    it('allows sales_margin up to 99.99', function () {
        $this->actingAs($this->adminUser)
            ->patch(route('prices.margin.update', $this->product), [
                'sales_margin' => '99.99',
            ])
            ->assertRedirect()
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'sales_margin' => '99.99',
        ]);
    });

    it('rejects invalid sales_margin values', function (mixed $invalidMargin) {
        $this->actingAs($this->adminUser)
            ->patch(route('prices.margin.update', $this->product), [
                'sales_margin' => $invalidMargin,
            ])
            ->assertSessionHasErrors('sales_margin');
    })->with([
        'no numérico' => ['not-a-number'],
        'negativo' => [-1],
        'igual a 100' => [100],
        'tres decimales' => ['12.345'],
    ]);

    it('forbids produccion and comercial from updating sales_margin', function (string $roleProp) {
        $this->actingAs($this->{$roleProp})
            ->patch(route('prices.margin.update', $this->product), [
                'sales_margin' => '30.00',
            ])
            ->assertForbidden();
    })->with([
        'producción' => ['productionUser'],
        'comercial' => ['comercialUser'],
    ]);
});

describe('previewMargin', function () {
    it('returns whole-peso preview prices without saving the margin', function () {
        $this->actingAs($this->adminUser)
            ->postJson(route('prices.margin.preview', $this->product), [
                'sales_margin' => '20.00',
            ])
            ->assertOk()
            ->assertExactJson([
                'product_sales_price' => 115,
                'variants' => [
                    [
                        'id' => $this->variant->id,
                        'sales_price' => 575,
                    ],
                ],
            ]);

        // No modifica el margen guardado.
        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'sales_margin' => '25.00',
        ]);
    });

    it('validates sales_margin on preview', function () {
        $this->actingAs($this->adminUser)
            ->postJson(route('prices.margin.preview', $this->product), [
                'sales_margin' => '100',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_margin');
    });

    it('forbids comercial from previewing margin', function () {
        $this->actingAs($this->comercialUser)
            ->postJson(route('prices.margin.preview', $this->product), [
                'sales_margin' => '20.00',
            ])
            ->assertForbidden();
    });
});
