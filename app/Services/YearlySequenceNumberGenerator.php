<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Consecutivo anual con prefijo (`OP-AAAA-NNNN`, `MP-AAAA-NNNN`): año de la planta, el mayor del año más uno, con
 * relleno a 4 dígitos (pasado 9999 sigue con 5).
 */
final class YearlySequenceNumberGenerator
{
    public function __construct(
        private readonly TimezoneService $timezone,
    ) {}

    /**
     * Debe llamarse dentro de una transacción (`DB::transaction`): en PostgreSQL el bloqueo vive hasta que termina la
     * transacción del llamador, y así dos registros simultáneos no reciben el mismo número.
     *
     * @param  Builder<Model>  $query  la tabla donde vive el número
     * @param  int  $lockKey  llave propia de cada consecutivo para `pg_advisory_xact_lock`
     */
    public function next(Builder $query, string $column, string $code, int $lockKey): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El consecutivo anual debe generarse dentro de una transacción.');
        }

        // Año de la planta: el 31-dic después de las 7 p. m. ya es 1-ene en UTC.
        $year = $this->timezone->nowInPlant()->year;
        $prefix = "{$code}-{$year}-";

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [$lockKey, $year]);
        }

        // Solo cuentan los sufijos de puros dígitos: un código escrito a mano como `MP-2026-0007-B` o `MP-2026-1e5`
        // no mueve el consecutivo (`(int)` los leería como 7 y 100000). Se filtra en PHP y no en SQL: convertir texto
        // a número no se comporta igual en SQLite y en PostgreSQL.
        $last = (int) $query
            ->where($column, 'like', $prefix.'%')
            ->pluck($column)
            ->map(fn (string $number): string => substr($number, strlen($prefix)))
            ->filter(fn (string $suffix): bool => ctype_digit($suffix))
            ->map(fn (string $suffix): int => (int) $suffix)
            ->max();

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
