<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Rules\UniqueIgnoringCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

function uniqueIgnoringCasePasses(UniqueIgnoringCase $rule, string $value): bool
{
    return Validator::make(['value' => $value], ['value' => [$rule]])->passes();
}

test('con alcance, el valor solo está tomado dentro de ese alcance', function (): void {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'presentation_label' => 'Galón']);

    $inProduct = new UniqueIgnoringCase('product_variants', 'presentation_label', null, ['product_id' => $product->id]);
    $inOther = new UniqueIgnoringCase('product_variants', 'presentation_label', null, ['product_id' => $other->id]);
    $ignoringItself = new UniqueIgnoringCase('product_variants', 'presentation_label', $variant->id, ['product_id' => $product->id]);

    expect(uniqueIgnoringCasePasses($inProduct, 'galón'))->toBeFalse()
        ->and(uniqueIgnoringCasePasses($inProduct, 'Cuñete'))->toBeTrue()
        ->and(uniqueIgnoringCasePasses($inOther, 'Galón'))->toBeTrue()
        ->and(uniqueIgnoringCasePasses($ignoringItself, 'Galón'))->toBeTrue();
});

test('sin alcance, busca en toda la tabla como antes', function (): void {
    ProductVariant::factory()->create(['presentation_label' => 'Galón']);

    expect(uniqueIgnoringCasePasses(new UniqueIgnoringCase('product_variants', 'presentation_label'), 'GALóN'))->toBeFalse()
        ->and(uniqueIgnoringCasePasses(new UniqueIgnoringCase('product_variants', 'presentation_label'), 'Cuarto'))->toBeTrue();
});
