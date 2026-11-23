<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retira `production_order_batch_consumptions.used_kg` (kilos usados por lote): desde el PR E1b (#336) lo gastado va
 * por línea de producto (`production_order_product_lines.actual_kg`) y ninguna imagen desplegada lo lee ni lo escribe.
 *
 * Compatible con volver a la imagen anterior: la de #336 en adelante ya no usa la columna. En producción llegan juntas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_batch_consumptions', function (Blueprint $table) {
            $table->dropColumn('used_kg');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_batch_consumptions', function (Blueprint $table) {
            $table->decimal('used_kg', 12, 4)->nullable()->after('units');
        });
    }
};
