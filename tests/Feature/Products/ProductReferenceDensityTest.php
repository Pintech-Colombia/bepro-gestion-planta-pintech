<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

// Peso por galón de referencia del producto: para la lista de precios cuando el producto va dentro de la fórmula de otro.
// Es una propiedad física, no un costo: lo edita y lo ve quien edita o ve el producto.

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->category = ProductCategory::create(['name' => 'Industrial']);
    $this->uom = UnitOfMeasure::create(['code' => 'GAL', 'name' => 'Galón', 'symbol' => 'gal']);
    $this->product = Product::create([
        'name' => 'Esmalte referencia',
        'category_id' => $this->category->id,
        'unit_of_measure_id' => $this->uom->id,
        'price_threshold' => 3,
        'cif_percentage' => 10,
    ]);
});

/**
 * @return array<string, mixed>
 */
function referenceDensityPayload(Product $product, mixed $density): array
{
    return [
        'name' => $product->name,
        'description' => null,
        'category_id' => $product->category_id,
        'unit_of_measure_id' => $product->unit_of_measure_id,
        'cif_percentage' => '10',
        'price_threshold' => '3',
        'reference_density_kg_per_gallon' => $density,
        'is_active' => true,
    ];
}

it('guarda el peso de referencia, lo audita y lo muestra en la ficha y el formulario', function (): void {
    actingAs($this->admin)
        ->put(route('products.update', $this->product), referenceDensityPayload($this->product, '4.2156'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.index'));

    expect($this->product->fresh()->reference_density_kg_per_gallon)->toBe('4.2156');

    $activity = Activity::query()->where('log_name', 'productos')->where('event', 'updated')->latest('id')->firstOrFail();
    expect($activity->properties['attributes'])->toBe(['reference_density_kg_per_gallon' => '4.2156'])
        ->and($activity->properties['old'])->toBe(['reference_density_kg_per_gallon' => null]);

    actingAs($this->admin)
        ->get(route('products.show', $this->product))
        ->assertInertia(fn (Assert $page) => $page->where('product.reference_density_kg_per_gallon', '4.2156'));
    actingAs($this->admin)
        ->get(route('products.edit', $this->product))
        ->assertInertia(fn (Assert $page) => $page->where('product.reference_density_kg_per_gallon', '4.2156'));
});

it('acepta vaciarlo y crearlo sin él', function (): void {
    $this->product->update(['reference_density_kg_per_gallon' => '4.2']);

    actingAs($this->admin)
        ->put(route('products.update', $this->product), referenceDensityPayload($this->product, ''))
        ->assertSessionHasNoErrors();

    expect($this->product->fresh()->reference_density_kg_per_gallon)->toBeNull();

    actingAs($this->admin)
        ->post(route('products.store'), [
            ...referenceDensityPayload($this->product, null),
            'name' => 'Producto nuevo',
        ])
        ->assertSessionHasNoErrors();

    expect(Product::query()->where('name', 'PRODUCTO NUEVO')->sole()->reference_density_kg_per_gallon)->toBeNull();
});

it('rechaza un peso inválido', function (mixed $density): void {
    actingAs($this->admin)
        ->put(route('products.update', $this->product), referenceDensityPayload($this->product, $density))
        ->assertSessionHasErrors('reference_density_kg_per_gallon');

    expect($this->product->fresh()->reference_density_kg_per_gallon)->toBeNull();
})->with([
    'cero' => ['0'],
    'negativo' => ['-4.2'],
    'más de 4 decimales' => ['4.21567'],
    'más de 6 enteros' => ['1000000'],
    'texto' => ['pesado'],
]);

it('lo edita y lo ve quien edita productos sin ver costos', function (): void {
    $role = Role::create(['name' => 'catalogo-sin-costos', 'guard_name' => 'web']);
    $role->syncPermissions([Permission::ProductsView->value, Permission::ProductsEdit->value]);
    $editor = User::factory()->create();
    $editor->assignRole($role);

    // Sin costos el formulario no envía CIF ni umbral.
    $payload = referenceDensityPayload($this->product, '3.9');
    unset($payload['cif_percentage'], $payload['price_threshold']);

    actingAs($editor)
        ->put(route('products.update', $this->product), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.index'));

    expect($this->product->fresh())
        ->reference_density_kg_per_gallon->toBe('3.9000')
        ->cif_percentage->toBe('10.00');

    actingAs($editor)
        ->get(route('products.edit', $this->product))
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.reference_density_kg_per_gallon', '3.9000')
            ->missing('product.cif_percentage'));
});
