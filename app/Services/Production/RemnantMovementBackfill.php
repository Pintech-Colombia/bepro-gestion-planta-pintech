<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\RemnantMovementType;
use App\Services\DecimalCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relleno del historial de los saldos de PT creados antes de que existiera. Lo llama la migración 2026_11_18_100001;
 * está aquí, y no en ella, para poder probarlo.
 *
 * Por cada saldo sin movimientos: su nacimiento (los kilos originales, su OP, quien la completó y la fecha del saldo) y un
 * consumo por cada línea aplicada (su OP, quien la planeó y la fecha en que se aplicó), en orden. Los kilos que quedan
 * después de cada uno se reconstruyen restando. Las líneas planeadas sin aplicar no movieron kilos: no son movimientos.
 * Si al final la cuenta no da los kilos disponibles del saldo (un dato viejo que no cuadra), se agrega una corrección
 * sin usuario que lo dice, para que el historial termine donde está el saldo.
 *
 * Escribe con el constructor de consultas, sin modelos: no deja registros de auditoría por cada movimiento y no depende
 * de cómo evolucione el modelo. Solo toca saldos sin movimientos, así que correrlo dos veces no cambia nada.
 */
class RemnantMovementBackfill
{
    private const CHUNK_SIZE = 500;

    public const MISMATCH_NOTE = 'Ajuste al crear el historial: los movimientos anteriores no daban los kilos disponibles del saldo.';

    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /** Devuelve cuántos saldos quedaron con historial. */
    public function run(): int
    {
        $filled = 0;

        DB::table('production_remnants')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('production_remnant_movements')
                ->whereColumn('production_remnant_movements.production_remnant_id', 'production_remnants.id'))
            ->select(['id', 'source_order_id', 'original_quantity_kg', 'available_quantity_kg', 'created_by', 'created_at'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $remnants) use (&$filled): void {
                $consumptions = DB::table('remnant_consumptions')
                    ->whereIn('remnant_id', $remnants->pluck('id')->all())
                    ->whereNotNull('applied_at')
                    ->orderBy('applied_at')
                    ->orderBy('id')
                    ->get(['id', 'remnant_id', 'target_order_id', 'quantity_kg', 'consumed_by', 'applied_at'])
                    ->groupBy('remnant_id');

                $rows = [];

                foreach ($remnants as $remnant) {
                    array_push($rows, ...$this->movementsFor($remnant, $consumptions->get((int) $remnant->id, collect())));
                    $filled++;
                }

                foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                    DB::table('production_remnant_movements')->insert($chunk);
                }
            });

        return $filled;
    }

    /**
     * @param  Collection<int, object>  $consumptions  las líneas aplicadas del saldo, en orden
     * @return list<array<string, mixed>>
     */
    private function movementsFor(object $remnant, Collection $consumptions): array
    {
        $balance = $this->calculator->round((string) $remnant->original_quantity_kg, 4);

        $remnantId = (int) $remnant->id;

        $rows = [$this->row($remnantId, RemnantMovementType::Birth, $balance, $balance, [
            'production_order_id' => $remnant->source_order_id,
            'user_id' => $remnant->created_by,
        ], $remnant->created_at ?? now())];

        foreach ($consumptions as $consumption) {
            $quantity = $this->calculator->round((string) $consumption->quantity_kg, 4);
            $balance = $this->calculator->sub($balance, $quantity, 4);

            $rows[] = $this->row($remnantId, RemnantMovementType::Consumption, $quantity, $balance, [
                'production_order_id' => $consumption->target_order_id,
                'remnant_consumption_id' => $consumption->id,
                // Los consumos nuevos quedan a nombre de quien completa la orden, y ese dato no existía: se deja vacío en
                // vez de poner a quien planeó la línea (`consumed_by`), que no es lo mismo.
                'user_id' => null,
            ], $consumption->applied_at);
        }

        $available = $this->calculator->round((string) $remnant->available_quantity_kg, 4);
        $difference = $this->calculator->sub($available, $balance, 4);

        if (! $this->calculator->isZero($difference)) {
            $rows[] = $this->row($remnantId, RemnantMovementType::Correction, $difference, $available, [
                'notes' => self::MISMATCH_NOTE,
            ], now());
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function row(int $remnantId, RemnantMovementType $type, string $quantityKg, string $balanceAfterKg, array $attributes, mixed $at): array
    {
        return [
            'production_remnant_id' => $remnantId,
            'type' => $type->value,
            'quantity_kg' => $quantityKg,
            'balance_after_kg' => $balanceAfterKg,
            'production_order_id' => null,
            'remnant_consumption_id' => null,
            'notes' => null,
            'user_id' => null,
            ...$attributes,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
