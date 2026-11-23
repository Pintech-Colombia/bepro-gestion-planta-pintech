<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El motivo de sobreconsumo también para un producto terminado que la OP lleva como ingrediente (PR E2a de PT
 * consumible): cada fila es de una materia prima **o** de un producto, nunca de los dos.
 *
 * Compatible con volver a la imagen anterior: agrega una columna nula y deja nula `raw_material_id`, que esa imagen
 * siempre escribe. El CHECK solo en PostgreSQL (SQLite no admite agregarlo después de crear la tabla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_consumption_reasons', function (Blueprint $table) {
            $table->foreignId('raw_material_id')->nullable()->change();
            $table->foreignId('product_id')->nullable()->after('raw_material_id')->constrained()->restrictOnDelete();

            $table->unique(['production_order_id', 'product_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE production_order_consumption_reasons ADD CONSTRAINT production_order_consumption_reasons_one_subject CHECK ((raw_material_id IS NULL) <> (product_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE production_order_consumption_reasons DROP CONSTRAINT IF EXISTS production_order_consumption_reasons_one_subject');
        }

        // Los motivos de producto no caben en la tabla anterior.
        DB::table('production_order_consumption_reasons')->whereNotNull('product_id')->delete();

        Schema::table('production_order_consumption_reasons', function (Blueprint $table) {
            $table->dropUnique(['production_order_id', 'product_id']);
            $table->dropConstrainedForeignId('product_id');
            $table->foreignId('raw_material_id')->nullable(false)->change();
        });
    }
};
