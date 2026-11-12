<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;

final class RawMaterialLotNumberGenerator
{
    public function __construct(
        private readonly TimezoneService $timezone,
    ) {}

    /**
     * Genera el siguiente número de lote (`MP-AAAA-NNNN`) del año en curso en la planta.
     *
     * Debe llamarse dentro de una transacción (`DB::transaction`): el bloqueo transaccional de PostgreSQL vive hasta
     * que termina la transacción del llamador.
     */
    public function next(): string
    {
        $year = $this->timezone->nowInPlant()->year;
        $prefix = "MP-{$year}-";

        // El bloqueo vive hasta el final de la transacción del llamador, así que `next()` debe llamarse dentro de una.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [801341, $year]);
        }

        $last = (int) InventoryBatch::query()
            ->where('lot_number', 'like', $prefix.'%')
            ->pluck('lot_number')
            ->map(fn (string $lotNumber): int => (int) substr($lotNumber, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}

