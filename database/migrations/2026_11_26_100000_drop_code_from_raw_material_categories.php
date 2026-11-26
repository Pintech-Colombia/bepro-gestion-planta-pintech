<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 2 de 2 para quitar el código de las categorías de materia prima: borra la columna, que desde el paso 1
 * (`2026_11_25_300000_make_raw_material_category_code_nullable`, ya desplegado) nadie lee ni escribe. Una categoría se
 * identifica por familia y nombre (índice `raw_material_categories_family_name_lower_unique`).
 *
 * El índice único se quita primero: en PostgreSQL se iría solo con la columna, pero SQLite no deja borrar una columna
 * indexada. Borrar una columna no reconstruye la tabla (SQLite ≥ 3.35 lo hace con ALTER TABLE), así que el índice de
 * expresión `(family_id, LOWER(name))` no se toca.
 */
return new class extends Migration
{
    private const CODE_INDEX = 'raw_material_categories_code_unique';

    public function up(): void
    {
        Schema::table('raw_material_categories', function (Blueprint $table) {
            $table->dropUnique(self::CODE_INDEX);
        });

        Schema::table('raw_material_categories', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        // Vuelve vacía y con nulos, como la dejó el paso 1: su down la rellena con `CAT-<id>` y la vuelve a exigir.
        Schema::table('raw_material_categories', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('id');
            $table->unique('code', self::CODE_INDEX);
        });
    }
};
