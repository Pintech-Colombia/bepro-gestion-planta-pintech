<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borra `product_variants.name` (segunda parte de «Variante sin nombre»). Desde el #302 la variante se identifica por su
 * presentación y nada lee esta columna, así que la imagen anterior sigue funcionando si hubiera que volver atrás.
 *
 * Primero el índice: SQLite no deja borrar una columna indexada. El índice único de la presentación
 * (`product_variants_product_presentation_lower_unique`) no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        // Vuelve nula, como la dejó el #302 (nada la lee). Si se sigue revirtiendo, el `down` de
        // 2026_11_11_100000_make_name_nullable_on_product_variants_table la rellena con «Producto - Presentación».
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('name', 100)->nullable();
            $table->index('name');
        });
    }
};
