<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Familias de materia prima (Aditivo, Pigmento, Resina…): agrupan las categorías para buscar e informar por familia.
     * La materia prima sigue apuntando solo a su categoría; el tipo de insumo se queda en la categoría, porque una
     * familia como «Envase y empaque» reúne envases y empaque secundario.
     */
    public function up(): void
    {
        Schema::create('raw_material_families', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // Único sin distinguir mayúsculas, como los demás catálogos que conservan las mayúsculas del nombre.
        DB::statement('CREATE UNIQUE INDEX raw_material_families_name_lower_unique ON raw_material_families (LOWER(name))');

        // SQLite agrega la clave foránea reconstruyendo la tabla, y la reconstrucción no sabe copiar el índice de expresión
        // del nombre: se quita antes y se vuelve a crear después. PostgreSQL altera la tabla sin tocarlo.
        $this->withoutCategoryNameIndexOnSqlite(function (): void {
            Schema::table('raw_material_categories', function (Blueprint $table) {
                // Nula en la base para no romper las categorías existentes; el formulario la exige al crear y al editar.
                $table->foreignId('family_id')
                    ->nullable()
                    ->after('description')
                    ->constrained('raw_material_families')
                    ->restrictOnDelete();

                // PostgreSQL no indexa las claves foráneas por sí solo.
                $table->index('family_id');
            });
        });
    }

    public function down(): void
    {
        $this->withoutCategoryNameIndexOnSqlite(function (): void {
            Schema::table('raw_material_categories', function (Blueprint $table) {
                $table->dropIndex(['family_id']);
                $table->dropConstrainedForeignId('family_id');
            });
        });

        Schema::dropIfExists('raw_material_families');
    }

    private function withoutCategoryNameIndexOnSqlite(Closure $alter): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            DB::statement('DROP INDEX IF EXISTS raw_material_categories_name_lower_unique');
        }

        $alter();

        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX raw_material_categories_name_lower_unique ON raw_material_categories (LOWER(name))');
        }
    }
};
