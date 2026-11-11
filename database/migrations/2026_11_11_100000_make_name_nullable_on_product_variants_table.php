<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La presentación identifica la variante dentro de su producto: el nombre («producto - presentación») deja de usarse.
 * La columna queda nula hasta que una migración posterior la borre; así un despliegue anterior sigue funcionando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->change();
        });
    }

    /**
     * Vuelve a armar el nombre como estaba («producto - presentación») en las variantes que no lo tienen. Se arma en PHP
     * para no depender de la concatenación de cada motor.
     */
    public function down(): void
    {
        DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('product_variants.name')
            ->select(['product_variants.id', 'products.name as product_name', 'product_variants.presentation_label'])
            ->chunkById(500, function ($variants): void {
                foreach ($variants as $variant) {
                    $name = $variant->presentation_label !== null && $variant->presentation_label !== ''
                        ? "{$variant->product_name} - {$variant->presentation_label}"
                        : (string) $variant->product_name;

                    DB::table('product_variants')
                        ->where('id', $variant->id)
                        ->update(['name' => mb_substr($name, 0, 100)]);
                }
            }, 'product_variants.id', 'id');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('name', 100)->nullable(false)->change();
        });
    }
};
