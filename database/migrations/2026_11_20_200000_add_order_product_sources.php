<?php

declare(strict_types=1);

use App\Services\Production\RemnantConsumptionLineBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Productos a usar» de una OP: el producto terminado que lleva como ingrediente y de dónde sale cada kilo.
 * - `production_order_product_lines`: una línea por producto, copiada de la fórmula (con sus kilos planeados) o
 *   agregada en la OP al usar un saldo o un lote de un producto que la fórmula no lleva.
 * - `production_order_batch_consumptions`: los lotes de PT que usa la línea, en unidades enteras; al completar guardan
 *   los kilos usados, lo que quedó en el envase abierto y su costo.
 * - `remnant_consumptions.production_order_product_line_id`: los saldos planeados pasan a colgar de una línea. Los que
 *   ya existen reciben una línea «agregada» de su producto en su OP (`RemnantConsumptionLineBackfill`).
 * - `production_remnant_movements.production_order_batch_consumption_id`: el movimiento del saldo que recibe lo que quedó
 *   en un envase abierto apunta al lote consumido.
 *
 * Compatible con volver a la imagen anterior: solo agrega tablas y columnas nulas que esa imagen no lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_product_lines', function (Blueprint $table) {
            $table->id();
            // RESTRICT: las líneas son historial de la orden (las órdenes no se eliminan).
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('step_order')->nullable();
            // Nulo en una línea agregada en la OP: no viene de la fórmula, no tiene plan.
            $table->decimal('planned_kg', 12, 4)->nullable();
            $table->string('origin', 20);
            $table->timestamps();

            $table->index(['production_order_id', 'product_id']);
        });

        Schema::create('production_order_batch_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_order_product_line_id')->constrained('production_order_product_lines')->restrictOnDelete();
            $table->foreignId('finished_product_batch_id')->constrained('finished_product_batches')->restrictOnDelete();
            // La bodega de donde salen las unidades: la de la orden.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->integer('units');
            $table->decimal('used_kg', 12, 4)->nullable();
            // Lo que quedó en el envase abierto, pesado. Nulo: no quedó nada.
            $table->decimal('leftover_kg', 12, 4)->nullable();
            // Kilos por unidad del lote, fijados al completar.
            $table->decimal('kg_per_unit', 12, 4)->nullable();
            $table->decimal('consumed_cost', 16, 4)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['finished_product_batch_id', 'warehouse_id']);
            $table->index('production_order_id');
        });

        Schema::table('remnant_consumptions', function (Blueprint $table) {
            $table->foreignId('production_order_product_line_id')->nullable()->after('target_order_id')
                ->constrained('production_order_product_lines')->restrictOnDelete();
            $table->index('production_order_product_line_id');
        });

        Schema::table('production_remnant_movements', function (Blueprint $table) {
            $table->foreignId('production_order_batch_consumption_id')->nullable()->after('remnant_consumption_id')
                ->constrained('production_order_batch_consumptions')->restrictOnDelete();
        });

        // Datos: los saldos planeados o aplicados que ya existen (staging) cuelgan de una línea de su producto.
        app(RemnantConsumptionLineBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('production_remnant_movements', function (Blueprint $table) {
            $table->dropForeign(['production_order_batch_consumption_id']);
            $table->dropColumn('production_order_batch_consumption_id');
        });

        Schema::table('remnant_consumptions', function (Blueprint $table) {
            $table->dropForeign(['production_order_product_line_id']);
            $table->dropIndex(['production_order_product_line_id']);
            $table->dropColumn('production_order_product_line_id');
        });

        Schema::dropIfExists('production_order_batch_consumptions');
        Schema::dropIfExists('production_order_product_lines');
    }
};
