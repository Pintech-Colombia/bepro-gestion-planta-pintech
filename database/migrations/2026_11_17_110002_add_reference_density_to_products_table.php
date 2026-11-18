<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peso por galón de referencia del producto (kg/gal), para la lista de precios cuando el producto va dentro de la fórmula
 * de otro. Mismo tipo que la densidad de la OP. Acepta nulo: la imagen anterior sigue funcionando sin conocerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reference_density_kg_per_gallon', 10, 4)->nullable()->after('price_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reference_density_kg_per_gallon');
        });
    }
};
