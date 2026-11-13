<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El sistema asigna `MP-AAAA-NNNN` al crear un lote de materia prima. El índice único parcial protege esos códigos
     * sin restringir los lotes anteriores escritos a mano.
     */
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX inventory_batches_generated_lot_number_unique ON inventory_batches (lot_number) WHERE lot_number LIKE 'MP-%'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_batches_generated_lot_number_unique');
    }
};
