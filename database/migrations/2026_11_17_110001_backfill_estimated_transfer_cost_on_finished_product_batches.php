<?php

declare(strict_types=1);

use App\Services\FinishedInventory\EstimatedTransferCostBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Costo de traslado de los lotes de PT que ya existen (los de staging): el costo de su entrada de producción con el CIF
 * actual del producto, marcado como estimado. La lógica vive en EstimatedTransferCostBackfill para poder probarla.
 * En una base sin lotes no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(EstimatedTransferCostBackfill::class)->run();
    }

    public function down(): void
    {
        // La migración anterior borra las columnas al revertir: no hay nada que deshacer aquí.
    }
};
