<?php

declare(strict_types=1);

/*
 * Un día de planta como rango de instantes en UTC, para filtrar columnas `timestamp` (`created_at`). Bogotá es UTC-5
 * todo el año.
 */

use App\Services\TimezoneService;
use Illuminate\Support\Facades\DB;

it('converts a plant day into UTC bounds, with an exclusive upper bound', function (): void {
    $timezone = app(TimezoneService::class);

    expect($timezone->plantDayStartUtc('2026-11-14')->format('Y-m-d H:i:s e'))->toBe('2026-11-14 05:00:00 UTC')
        ->and($timezone->nextPlantDayStartUtc('2026-11-14')->format('Y-m-d H:i:s e'))->toBe('2026-11-15 05:00:00 UTC');
});

it('runs the PostgreSQL session in UTC', function (): void {
    expect(DB::selectOne('SHOW TIME ZONE')->TimeZone)->toBe('UTC');
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Solo PostgreSQL tiene zona horaria de sesión.');
