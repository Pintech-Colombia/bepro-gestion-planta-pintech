<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tolerancia de consumo por familia de materia prima (la jefa: resinas y solventes 10 %, lo demás el general):
 * - `raw_material_families.consumption_tolerance_percentage`: el % de la familia; nulo = el general
 *   (`config/production.php`).
 * - `production_order_details.consumption_tolerance_percentage`: el % con que se evaluó cada línea al completar la OP.
 *   La completada sigue mostrando lo que se evaluó aunque cambie el de la familia; nulo en las completadas antes, que
 *   usan el de la orden (`production_orders.consumption_tolerance_percentage`).
 *
 * Compatible con volver a la imagen anterior: solo agrega columnas nulas que esa imagen no lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_families', function (Blueprint $table) {
            // Misma precisión que la tolerancia de la OP.
            $table->decimal('consumption_tolerance_percentage', 5, 2)->nullable()->after('description');
        });

        Schema::table('production_order_details', function (Blueprint $table) {
            $table->decimal('consumption_tolerance_percentage', 5, 2)->nullable()->after('total_cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_details', function (Blueprint $table) {
            $table->dropColumn('consumption_tolerance_percentage');
        });

        Schema::table('raw_material_families', function (Blueprint $table) {
            $table->dropColumn('consumption_tolerance_percentage');
        });
    }
};
