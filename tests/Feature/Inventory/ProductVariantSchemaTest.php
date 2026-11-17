<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('el esquema incluye tabla de variantes de producto', function () {
    expect(Schema::hasTable('product_variants'))->toBeTrue();

    expect(Schema::hasColumns('product_variants', [
        'id',
        'product_id',
        'code',
        'unit_of_measure_id',
        'presentation_value',
        'presentation_label',
    ]))->toBeTrue();

    // Portable entre SQLite (tests) y PostgreSQL (dev y producción). La presentación identifica la variante: la columna
    // `name` ya no existe y el índice único de la presentación sigue en su lugar.
    $columns = collect(Schema::getColumns('product_variants'))->keyBy('name');
    expect($columns['presentation_label']['nullable'])->toBeFalse()
        ->and($columns->has('name'))->toBeFalse()
        ->and(collect(Schema::getIndexes('product_variants'))->pluck('name'))
        ->toContain('product_variants_product_presentation_lower_unique');
});

test('las tablas operativas incluyen product_variant_id para migracion gradual', function () {
    expect(Schema::hasColumn('finished_inventories', 'product_variant_id'))->toBeTrue();
    expect(Schema::hasColumn('finished_inventory_movements', 'product_variant_id'))->toBeTrue();
});
