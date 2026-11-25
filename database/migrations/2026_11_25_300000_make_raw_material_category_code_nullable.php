<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 1 de 2 para quitar el código de las categorías de materia prima: el sistema deja de usarlo (una categoría se
 * identifica por familia y nombre, índice `raw_material_categories_family_name_lower_unique`) y la columna pasa a
 * admitir nulos, porque las categorías nuevas ya no lo llevan. El índice único se queda: admite varios nulos.
 *
 * El paso 2, en un despliegue posterior, borra la columna. Va aparte para que volver a la imagen anterior (que todavía
 * lee el código) siga funcionando con las categorías que ya existen.
 *
 * SQLite cambia una columna reconstruyendo la tabla, y al recrear el índice de expresión `(family_id, LOWER(name))` lo
 * deja solo sobre `family_id`: se quita antes y se vuelve a crear después (en PostgreSQL es un ALTER y no hace falta,
 * pero así queda igual en los dos motores).
 */
return new class extends Migration
{
    private const FAMILY_NAME_INDEX = 'raw_material_categories_family_name_lower_unique';

    public function up(): void
    {
        $this->withoutFamilyNameIndex(function (): void {
            Schema::table('raw_material_categories', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->change();
            });
        });
    }

    public function down(): void
    {
        // Las categorías creadas sin código impedirían volver a exigirlo: se les pone uno por su id, que no puede repetirse
        // (el nombre sí: «BASE AGUA» en dos familias). `||` concatena en PostgreSQL y en SQLite.
        DB::table('raw_material_categories')->whereNull('code')->update(['code' => DB::raw("'CAT-' || id")]);

        $this->withoutFamilyNameIndex(function (): void {
            Schema::table('raw_material_categories', function (Blueprint $table) {
                $table->string('code', 50)->nullable(false)->change();
            });
        });
    }

    private function withoutFamilyNameIndex(callable $change): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::FAMILY_NAME_INDEX);
        $change();
        DB::statement('CREATE UNIQUE INDEX '.self::FAMILY_NAME_INDEX.' ON raw_material_categories (family_id, LOWER(name))');
    }
};
