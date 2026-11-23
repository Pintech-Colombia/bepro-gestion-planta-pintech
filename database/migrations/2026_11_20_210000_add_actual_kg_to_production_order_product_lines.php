<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo real gastado de cada producto de la OP, en kilos y por línea: el operario lo escribe al entregar en la tabla de
 * consumo, igual que lo real de cada materia prima. `production_order_batch_consumptions.used_kg` (kilos por lote) deja
 * de usarse y se retira en una migración posterior, cuando ninguna imagen desplegada lo lea.
 *
 * Compatible con volver a la imagen anterior: solo agrega una columna nula que esa imagen no lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_product_lines', function (Blueprint $table) {
            $table->decimal('actual_kg', 12, 4)->nullable()->after('planned_kg');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_product_lines', function (Blueprint $table) {
            $table->dropColumn('actual_kg');
        });
    }
};
