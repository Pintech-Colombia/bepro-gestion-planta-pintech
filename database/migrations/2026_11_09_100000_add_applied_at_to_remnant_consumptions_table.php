<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un saldo se planea en la OP y su inventario se mueve al completarla: `applied_at` dice cuándo se descontó, y nulo
     * es una línea planeada. Las filas que ya existen se descontaron al registrarlas, así que quedan aplicadas en ese
     * momento. El índice sirve a la suma de los kilos planeados por saldo.
     */
    public function up(): void
    {
        Schema::table('remnant_consumptions', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('consumed_at');
            $table->index(['remnant_id', 'applied_at']);
        });

        DB::table('remnant_consumptions')->update(['applied_at' => DB::raw('consumed_at')]);
    }

    public function down(): void
    {
        Schema::table('remnant_consumptions', function (Blueprint $table) {
            $table->dropIndex(['remnant_id', 'applied_at']);
            $table->dropColumn('applied_at');
        });
    }
};
