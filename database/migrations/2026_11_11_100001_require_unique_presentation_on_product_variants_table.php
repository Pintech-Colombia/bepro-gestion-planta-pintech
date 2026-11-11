<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La presentación pasa a ser obligatoria y única dentro del producto sin distinguir mayúsculas («Galón» y «galón» son la
 * misma), contando también las variantes desactivadas.
 *
 * Antes de cambiar la columna, la presentación vacía se llena con el nombre. La migración nunca borra ni renombra: si un
 * nombre no cabe en la presentación o dos variantes de un producto quedan con la misma, se detiene y dice cuáles son.
 *
 * El índice de expresión va después del `->change()`: en SQLite el cambio reconstruye la tabla.
 */
return new class extends Migration
{
    private const INDEX = 'product_variants_product_presentation_lower_unique';

    private const LABEL_MAX_LENGTH = 50;

    public function up(): void
    {
        $fills = [];
        $tooLong = [];
        $seen = [];
        $duplicates = [];

        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.name',
                'product_variants.presentation_label',
                'products.name as product_name',
            ])
            ->lazyById(500, 'product_variants.id', 'id');

        foreach ($variants as $variant) {
            $label = $variant->presentation_label;

            if ($label === null || trim($label) === '') {
                $label = trim((string) $variant->name);

                if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                    $tooLong[] = $variant->id;

                    continue;
                }

                $fills[$variant->id] = $label;
            }

            $key = $variant->product_id.'|'.mb_strtolower(trim($label));

            if (isset($seen[$key])) {
                $duplicates[$key] = "«{$variant->product_name}» · «".trim($label).'»';
            }

            $seen[$key] = true;
        }

        if ($tooLong !== []) {
            throw new RuntimeException(
                'Variantes sin presentación cuyo nombre está vacío o pasa de '.self::LABEL_MAX_LENGTH.' caracteres; '
                .'escribe su presentación a mano y vuelve a migrar. IDs: '.implode(', ', $tooLong)
            );
        }

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Variantes de un mismo producto con la misma presentación; corrígelas a mano y vuelve a migrar: '
                .implode('; ', $duplicates)
            );
        }

        foreach ($fills as $id => $label) {
            DB::table('product_variants')->where('id', $id)->update(['presentation_label' => $label]);
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('presentation_label', self::LABEL_MAX_LENGTH)->nullable(false)->change();
        });

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON product_variants (product_id, LOWER(presentation_label))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('presentation_label', self::LABEL_MAX_LENGTH)->nullable()->change();
        });
    }
};
