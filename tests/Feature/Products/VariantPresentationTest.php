<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\Client;
use App\Models\FinishedInventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\ProductionCostRecalculationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
 * La variante se identifica por su presentación: obligatoria y única dentro del producto sin distinguir mayúsculas,
 * contando también las desactivadas. El nombre («producto - presentación») ya no se lee ni se escribe.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = actingAsRole(SystemRole::Admin);

    $this->unit = UnitOfMeasure::factory()->create();
    $category = ProductCategory::create(['name' => 'Cat presentaciones']);
    $this->product = Product::create(['name' => 'BP AJUSTADOR IA-500', 'category_id' => $category->id,
        'unit_of_measure_id' => $this->unit->id, 'sales_margin' => 0, 'cif_percentage' => 15, 'price_threshold' => 3,
        'is_active' => true]);
    $this->otherProduct = Product::create(['name' => 'BP LACA IP-350', 'category_id' => $category->id,
        'unit_of_measure_id' => $this->unit->id, 'sales_margin' => 0, 'cif_percentage' => 15, 'price_threshold' => 3,
        'is_active' => true]);
    $this->galon = ProductVariant::factory()->create(['product_id' => $this->product->id, 'code' => 'VAR-GALON',
        'presentation_label' => 'Galón', 'unit_of_measure_id' => $this->unit->id, 'presentation_value' => 1,
        'package_raw_material_id' => null]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function variantPresentationPayload(object $t, array $overrides = []): array
{
    return array_replace([
        'code' => 'VAR-NUEVA',
        'presentation_label' => 'Cuñete 5G',
        'unit_of_measure_id' => $t->unit->id,
        'presentation_value' => '5',
    ], $overrides);
}

test('crear una presentación la exige', function (?string $label): void {
    $this->post(route('products.variants.store', $this->product), variantPresentationPayload($this, ['presentation_label' => $label]))
        ->assertSessionHasErrors('presentation_label');
})->with([
    'sin presentación' => [null],
    'vacía' => [''],
    'solo espacios' => ['   '],
]);

test('crear una presentación que el producto ya tiene da el mensaje, sin distinguir mayúsculas', function (string $label): void {
    $this->post(route('products.variants.store', $this->product), variantPresentationPayload($this, ['presentation_label' => $label]))
        // El mensaje repite lo escrito, ya sin los espacios de los extremos.
        ->assertSessionHasErrors(['presentation_label' => 'Este producto ya tiene la presentación «'.trim($label).'».']);

    expect(ProductVariant::query()->where('product_id', $this->product->id)->count())->toBe(1);
})->with([
    'igual' => ['Galón'],
    'en minúsculas' => ['galón'],
    'con espacios alrededor' => ['  Galón '],
]);

test('la presentación de una variante desactivada también cuenta', function (): void {
    $this->galon->update(['is_active' => false]);

    $this->post(route('products.variants.store', $this->product), variantPresentationPayload($this, ['presentation_label' => 'GALÓN']))
        ->assertSessionHasErrors('presentation_label');
})->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite', 'El LOWER() de SQLite no cambia la «Ó»: se prueba en PostgreSQL.');

test('la presentación de una variante desactivada también cuenta, sin tildes de por medio', function (): void {
    $this->galon->update(['is_active' => false]);

    $this->post(route('products.variants.store', $this->product), variantPresentationPayload($this, ['presentation_label' => 'galón']))
        ->assertSessionHasErrors('presentation_label');
});

test('la misma presentación en otro producto sí se puede', function (): void {
    $this->post(route('products.variants.store', $this->otherProduct), variantPresentationPayload($this, ['presentation_label' => 'Galón']))
        ->assertSessionHasNoErrors();

    expect(ProductVariant::query()->where('product_id', $this->otherProduct->id)->sole())
        ->presentation_label->toBe('Galón')
        ->name->toBeNull();
});

test('editar sin cambiar la presentación no choca consigo misma', function (): void {
    $this->patch(route('products.variants.update', [$this->product, $this->galon]), variantPresentationPayload($this, [
        'code' => $this->galon->code,
        'presentation_label' => 'Galón',
        'presentation_value' => '1',
    ]))->assertSessionHasNoErrors();
});

test('editar la presentación a otra que el producto ya tiene da error', function (): void {
    $cunete = ProductVariant::factory()->create(['product_id' => $this->product->id, 'presentation_label' => 'Cuñete 5G',
        'unit_of_measure_id' => $this->unit->id, 'package_raw_material_id' => null]);

    $this->patch(route('products.variants.update', [$this->product, $cunete]), variantPresentationPayload($this, [
        'code' => $cunete->code,
        'presentation_label' => 'galón',
    ]))->assertSessionHasErrors(['presentation_label' => 'Este producto ya tiene la presentación «galón».']);

    expect($cunete->fresh()->presentation_label)->toBe('Cuñete 5G');
});

test('el índice de la base rechaza la misma presentación en un producto, sin distinguir mayúsculas', function (): void {
    // Cada intento en su propio punto de guardado: en PostgreSQL un choque aborta la transacción de la prueba.
    $insert = fn (string $code, string $label) => DB::transaction(fn () => DB::table('product_variants')->insert([
        'product_id' => $this->product->id,
        'code' => $code,
        'unit_of_measure_id' => $this->unit->id,
        'presentation_label' => $label,
        'is_active' => true,
    ]));

    expect(fn () => $insert('VAR-DIRECTA-1', 'Galón'))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $insert('VAR-DIRECTA-2', 'GALóN'))->toThrow(UniqueConstraintViolationException::class);

    DB::table('product_variants')->insert([
        'product_id' => $this->otherProduct->id,
        'code' => 'VAR-DIRECTA-3',
        'unit_of_measure_id' => $this->unit->id,
        'presentation_label' => 'Galón',
        'is_active' => true,
    ]);

    expect(DB::table('product_variants')->where('presentation_label', 'Galón')->count())->toBe(2);
});

test('la auditoría nombra la variante con su producto y su presentación', function (): void {
    Activity::query()->delete();

    $this->post(route('products.variants.store', $this->product), variantPresentationPayload($this))
        ->assertSessionHasNoErrors();

    $log = Activity::query()->where('subject_type', ProductVariant::class)->sole();

    expect($log->description)->toBe('Variante de producto "BP AJUSTADOR IA-500 · Cuñete 5G" creada')
        ->and($log->properties['attributes'])->not->toHaveKey('name');
});

test('el mensaje de la cotización sin precio nombra producto y presentación', function (): void {
    $this->galon->update(['current_price' => null]);
    $client = Client::factory()->create();

    $this->actingAs(userWithRole(SystemRole::Commercial))
        ->post(route('quotations.store'), [
            'client_id' => $client->id,
            'quotation_date' => plantToday(),
            'iva_percentage' => '19',
            'items' => [[
                'product_id' => $this->product->id,
                'product_variant_id' => $this->galon->id,
                'type' => 'primer',
                'description' => 'Ajustador',
                'quantity' => 5,
                'price_adjustment_pct' => 0,
            ]],
        ])
        ->assertSessionHasErrors(['items' => 'El producto BP AJUSTADOR IA-500 · Galón no tiene precio de venta configurado.']);
});

test('las pantallas reciben la presentación de la variante, sin nombre y ordenadas por contenido', function (string $route, string $variantsPath): void {
    ProductVariant::factory()->create(['product_id' => $this->product->id, 'presentation_label' => 'Cuarto',
        'presentation_value' => '0.25', 'unit_of_measure_id' => $this->unit->id, 'package_raw_material_id' => null]);

    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('products', function ($products) use ($variantsPath): bool {
            $variants = collect(collect($products)->firstWhere('id', $this->product->id)[$variantsPath] ?? []);

            return $variants->pluck('presentation_label')->all() === ['Cuarto', 'Galón']
                && $variants->every(fn ($variant): bool => ! array_key_exists('name', (array) $variant));
        }));
})->with([
    'cotización' => ['quotations.create', 'variants'],
    'pedido' => ['sales-orders.create', 'variants'],
    'orden de producción' => ['production-orders.create', 'variants'],
]);

test('sin la columna name, las pantallas y formularios de variantes siguen funcionando', function (): void {
    // Red para la migración que borrará la columna: si alguna consulta todavía la pide, aquí falla.
    $warehouse = Warehouse::factory()->create();
    FinishedInventory::factory()->create(['product_variant_id' => $this->galon->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    Schema::table('product_variants', function (Blueprint $table): void {
        $table->dropIndex(['name']);
        $table->dropColumn('name');
    });

    foreach ([
        route('prices.index', ['search' => 'Galón']),
        route('quotations.create'),
        route('sales-orders.create'),
        route('production-orders.create'),
        route('products.show', $this->product),
        route('finished-inventory.index', ['search' => 'Galón']),
        route('finished-inventory-movements.index', ['search' => 'Galón']),
        route('warehouses.show', $warehouse),
    ] as $url) {
        $this->get($url)->assertOk();
    }

    $this->post(route('products.variants.store', $this->product), variantPresentationPayload($this))
        ->assertSessionHasNoErrors();
    $this->patch(route('products.variants.update', [$this->product, $this->galon]), variantPresentationPayload($this, [
        'code' => $this->galon->code,
        'presentation_label' => 'Galón 3,78 L',
        'presentation_value' => '1',
    ]))->assertSessionHasNoErrors();

    expect($this->galon->fresh()->presentation_label)->toBe('Galón 3,78 L');
})->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite', 'SQLite no da error por una columna inexistente entre comillas: se prueba en PostgreSQL.');

test('recalcular por un envase no consulta el producto de cada presentación y la auditoría la nombra', function (): void {
    $container = RawMaterial::factory()->create([
        'category_id' => RawMaterialCategory::factory()->container()->create()->id,
        'current_price' => '2000',
    ]);

    $variants = collect(range(1, 4))->map(function (int $i) use ($container): ProductVariant {
        $product = Product::factory()->create(['name' => "BP PRODUCTO {$i}", 'current_cost' => '10000',
            'cif_percentage' => '10', 'price_threshold' => '0']);

        return ProductVariant::factory()->create(['product_id' => $product->id, 'presentation_label' => 'Galón',
            'presentation_value' => 1, 'package_raw_material_id' => $container->id, 'label_raw_material_id' => null,
            'current_cost' => null, 'current_price' => null]);
    });

    Activity::query()->delete();
    DB::enableQueryLog();

    $repriced = app(ProductionCostRecalculationService::class)->repriceVariantsUsingPackagingMaterial($container->id);

    $productQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => preg_match('/from\s+"products"/i', $query['query']) === 1);
    DB::disableQueryLog();

    expect($repriced)->toBe(4)
        // Una sola consulta de productos para las cuatro presentaciones, no una por cada una.
        ->and($productQueries)->toHaveCount(1)
        ->and(Activity::query()->where('subject_type', ProductVariant::class)->pluck('description')->sort()->values()->all())
        ->toBe($variants->map(fn (ProductVariant $v): string => "Variante de producto \"{$v->product->name} · Galón\" actualizada")->sort()->values()->all());
});

test('las presentaciones de la lista de precios van ordenadas por contenido', function (): void {
    $this->galon->update(['current_price' => '100']);
    ProductVariant::factory()->create(['product_id' => $this->product->id, 'presentation_label' => 'Cuarto',
        'presentation_value' => '0.25', 'unit_of_measure_id' => $this->unit->id, 'package_raw_material_id' => null,
        'current_price' => '30']);

    $this->get(route('prices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('products.data', function ($products): bool {
            $variants = collect(collect($products)->firstWhere('id', $this->product->id)['variants'] ?? []);

            return $variants->pluck('presentation_label')->all() === ['Cuarto', 'Galón'];
        }));
});
