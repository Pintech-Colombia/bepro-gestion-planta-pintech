<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\ProductionOrderProductLineOrigin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuelga de una línea de producto los consumos de saldo que existían antes de las líneas («Productos a usar»). Lo llama
 * la migración 2026_11_20_200000; está aquí, y no en ella, para poder probarlo.
 *
 * Cada consumo sin línea recibe la línea de su producto (el del saldo) en su OP: si la OP ya la tiene, esa; si no, una
 * «agregada», sin kilos planeados (no viene de la fórmula). Los consumos de un mismo producto en la misma OP comparten
 * la línea. Un consumo sin OP queda sin línea.
 *
 * Escribe con el constructor de consultas, sin modelos: no deja registros de auditoría por fila y no depende de cómo
 * evolucione el modelo. Solo toca consumos sin línea, así que correrlo dos veces no cambia nada.
 */
class RemnantConsumptionLineBackfill
{
    private const CHUNK_SIZE = 500;

    /** Devuelve cuántos consumos quedaron con línea. */
    public function run(): int
    {
        $linked = 0;

        DB::table('remnant_consumptions')
            ->join('production_remnants', 'production_remnants.id', '=', 'remnant_consumptions.remnant_id')
            ->whereNull('remnant_consumptions.production_order_product_line_id')
            ->whereNotNull('remnant_consumptions.target_order_id')
            ->select([
                'remnant_consumptions.id',
                'remnant_consumptions.target_order_id',
                'production_remnants.product_id',
            ])
            ->chunkById(self::CHUNK_SIZE, function (Collection $consumptions) use (&$linked): void {
                foreach ($consumptions->groupBy(fn (object $row): string => $row->target_order_id.':'.$row->product_id) as $rows) {
                    $first = $rows->first();
                    $lineId = $this->lineFor((int) $first->target_order_id, (int) $first->product_id);

                    $linked += DB::table('remnant_consumptions')
                        ->whereIn('id', $rows->pluck('id')->all())
                        ->update(['production_order_product_line_id' => $lineId]);
                }
            }, 'remnant_consumptions.id', 'id');

        return $linked;
    }

    private function lineFor(int $orderId, int $productId): int
    {
        $existing = DB::table('production_order_product_lines')
            ->where('production_order_id', $orderId)
            ->where('product_id', $productId)
            ->orderBy('id')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('production_order_product_lines')->insertGetId([
            'production_order_id' => $orderId,
            'product_id' => $productId,
            'step_order' => null,
            'planned_kg' => null,
            'origin' => ProductionOrderProductLineOrigin::Added->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
