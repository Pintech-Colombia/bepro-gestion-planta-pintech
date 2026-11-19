<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIF de la OP: el del producto el día en que se completó. Con él la OP completada muestra sus costos con CIF aunque
 * después cambie el del producto, y cuadra con sus lotes y su saldo, que ya lo fijaron.
 *
 * Compatible con volver a la imagen anterior: la columna acepta nulo y la marca tiene valor por defecto, así que esa
 * imagen sigue completando órdenes sin conocerlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // Misma precisión que el CIF del producto (`products.cif_percentage`).
            $table->decimal('cif_percentage', 5, 2)->nullable()->after('density_kg_per_gallon');
            // true = calculado después, con el CIF que tenía el producto al migrar (OP sin lote ni saldo que lo fijara).
            $table->boolean('cif_percentage_estimated')->default(false)->after('cif_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['cif_percentage', 'cif_percentage_estimated']);
        });
    }
};
