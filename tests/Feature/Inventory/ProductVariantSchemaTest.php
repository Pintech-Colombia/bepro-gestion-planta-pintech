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

    // Portable entre SQLite (tests) y PostgreSQL (dev y producción). La presentación identifica la variante; el nombre
    // queda nulo hasta que una migración posterior borre la columna.
    $columns = collect(Schema::getColumns('product_variants'))->keyBy('name');
    expect($columns['presentation_label']['nullable'])->toBeFalse()
        ->and($columns['name']['nullable'])->toBeTrue();
});

test('las tablas operativas incluyen product_variant_id para migracion gradual', function () {
    expect(Schema::hasColumn('finished_inventories', 'product_variant_id'))->toBeTrue();
    expect(Schema::hasColumn('finished_inventory_movements', 'product_variant_id'))->toBeTrue();
});
