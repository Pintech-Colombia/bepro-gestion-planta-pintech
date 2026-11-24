<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El nombre de una categoría de materia prima pasa a ser único dentro de su familia, no en toda la tabla: «BASE AGUA»
     * existe en Resina y en Solvente. El código sigue siendo único en toda la tabla. Solo afloja la regla: el código
     * anterior sigue funcionando con este índice.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS raw_material_categories_name_lower_unique');
        DB::statement('CREATE UNIQUE INDEX raw_material_categories_family_name_lower_unique ON raw_material_categories (family_id, LOWER(name))');
    }

    /**
     * Falla si ya hay dos categorías con el mismo nombre en familias distintas: hay que renombrar una antes.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS raw_material_categories_family_name_lower_unique');
        DB::statement('CREATE UNIQUE INDEX raw_material_categories_name_lower_unique ON raw_material_categories (LOWER(name))');
    }
};
