<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `alerts.source_key`: qué produjo la alerta cuando no basta con la materia prima, el lote o la OP (hoy, cada recálculo de
 * costo que falla: `recalc:<tipo>:<id>`). Así una alerta se refresca y se resuelve por su origen, no por el texto del
 * mensaje.
 *
 * Compatible con volver a la imagen anterior: solo agrega una columna nula que esa imagen no lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('source_key', 100)->nullable()->after('production_order_id');
            $table->index(['source_key', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['source_key', 'is_resolved']);
            $table->dropColumn('source_key');
        });
    }
};
