<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sustancia química controlada: cada movimiento de su stock se registra en SICOQ (Ministerio de Justicia, Res. 0001 de
     * 2015 del Consejo Nacional de Estupefacientes). Por ahora solo la marca; el aviso por movimiento llega después.
     */
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->boolean('is_controlled_substance')->default(false)->after('tracks_inventory');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn('is_controlled_substance');
        });
    }
};
