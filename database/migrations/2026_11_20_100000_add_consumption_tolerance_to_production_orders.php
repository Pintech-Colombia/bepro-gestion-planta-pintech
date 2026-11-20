<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tolerancia de consumo y alerta de sobreconsumo:
 * - `production_orders.consumption_tolerance_percentage`: el % con que se evaluó el consumo al completar. Una OP
 *   completada sigue mostrando lo que se evaluó aunque el % cambie después; nula en las anteriores (no se marcan).
 * - `alerts.production_order_id`: la alerta de sobreconsumo enlaza a su OP.
 * - `production_order_consumption_reasons`: el motivo de cada materia prima que se pasó de lo planeado.
 *
 * Compatible con volver a la imagen anterior: solo agrega columnas nulas y una tabla que esa imagen no lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // Misma precisión que el CIF de la OP.
            $table->decimal('consumption_tolerance_percentage', 5, 2)->nullable()->after('cif_percentage_estimated');
        });

        Schema::table('alerts', function (Blueprint $table) {
            // RESTRICT: la alerta es historial de la orden (las órdenes no se eliminan).
            $table->foreignId('production_order_id')->nullable()->after('batch_id')
                ->constrained('production_orders')->restrictOnDelete();
            $table->index('production_order_id');
        });

        Schema::create('production_order_consumption_reasons', function (Blueprint $table) {
            $table->id();
            // Hijo de la orden, como sus ajustes de línea (CASCADE; las órdenes no se eliminan).
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_material_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['production_order_id', 'raw_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_consumption_reasons');

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropForeign(['production_order_id']);
            $table->dropIndex(['production_order_id']);
            $table->dropColumn('production_order_id');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('consumption_tolerance_percentage');
        });
    }
};
