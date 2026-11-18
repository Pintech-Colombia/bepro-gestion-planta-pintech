<?php

declare(strict_types=1);

use App\Services\Production\RemnantMovementBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Historial de los saldos que ya existen (los de staging): su nacimiento y cada consumo aplicado. La lógica vive en
 * RemnantMovementBackfill para poder probarla. En una base sin saldos no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RemnantMovementBackfill::class)->run();
    }

    public function down(): void
    {
        // La migración anterior borra la tabla al revertir: no hay nada que deshacer aquí.
    }
};
