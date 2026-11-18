<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una línea de fórmula puede ser un **producto** (PT consumible, PR D de «Plan — PT consumible»): la materia prima pasa a
 * nula y se agregan el producto y cómo se costea (granel o una presentación, P14).
 *
 * Compatible con la imagen anterior: solo crea líneas de materia prima, con las columnas nuevas vacías. La CHECK
 * (PostgreSQL; SQLite no acepta agregarla con ALTER) asegura que cada línea lleve una materia prima **o** un producto,
 * nunca los dos, y que la forma de costeo solo vaya en una línea de producto: nula (solo desde la carga inicial, que la
 * deja para que la jefa la complete), `bulk` sin presentación o `presentation` con una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formula_details', function (Blueprint $table) {
            $table->foreignId('raw_material_id')->nullable()->change();
            $table->foreignId('product_id')->nullable()->after('raw_material_id')->constrained('products')->restrictOnDelete();
            $table->string('costing_basis', 20)->nullable()->after('product_id');
            $table->foreignId('costing_variant_id')->nullable()->after('costing_basis')->constrained('product_variants')->restrictOnDelete();

            $table->index('product_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE formula_details ADD CONSTRAINT formula_details_material_or_product CHECK (
                    (raw_material_id IS NOT NULL AND product_id IS NULL AND costing_basis IS NULL AND costing_variant_id IS NULL)
                    OR (raw_material_id IS NULL AND product_id IS NOT NULL AND (
                        (costing_basis IS NULL AND costing_variant_id IS NULL)
                        OR (costing_basis = 'bulk' AND costing_variant_id IS NULL)
                        OR (costing_basis = 'presentation' AND costing_variant_id IS NOT NULL)
                    ))
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE formula_details DROP CONSTRAINT IF EXISTS formula_details_material_or_product');
        }

        Schema::table('formula_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('costing_variant_id');
            $table->dropColumn('costing_basis');
            $table->dropIndex(['product_id']);
            $table->dropConstrainedForeignId('product_id');
        });
        // `raw_material_id` queda nula: volver a NOT NULL fallaría con líneas de producto ya creadas.
    }
};
