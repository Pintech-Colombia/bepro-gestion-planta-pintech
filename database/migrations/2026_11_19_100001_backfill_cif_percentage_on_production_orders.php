<?php

declare(strict_types=1);

use App\Services\Production\ProductionOrderCifBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * CIF de las OP ya completadas (las de staging): el que fijaron sus lotes o su saldo, o el del producto marcado como
 * estimado. La lógica vive en ProductionOrderCifBackfill para poder probarla. En una base sin OP completadas no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ProductionOrderCifBackfill::class)->run();
    }

    public function down(): void
    {
        // La migración anterior borra las columnas al revertir: no hay nada que deshacer aquí.
    }
};
