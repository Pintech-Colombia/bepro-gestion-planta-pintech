<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo de traslado del lote de PT: lo que cuesta una unidad del lote con el CIF del producto, fijado al completar la OP
 * (el del movimiento de entrada sigue sin CIF). Es el costo con el que el lote entra a otra OP como insumo.
 *
 * Compatible con volver a la imagen anterior: la columna del costo acepta nulo y la marca tiene valor por defecto, así que
 * esa imagen sigue creando lotes sin conocerlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finished_product_batches', function (Blueprint $table) {
            $table->decimal('transfer_unit_cost', 16, 4)->nullable()->after('initial_quantity');
            // true = calculado después, con el CIF que tenía el producto al migrar (lotes anteriores a esta columna).
            $table->boolean('transfer_cost_estimated')->default(false)->after('transfer_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('finished_product_batches', function (Blueprint $table) {
            $table->dropColumn(['transfer_unit_cost', 'transfer_cost_estimated']);
        });
    }
};
