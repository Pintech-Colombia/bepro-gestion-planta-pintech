<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;

/**
 * Cuánto de cada saldo se puede todavía planear en una OP. El inventario de un saldo se mueve al completar la orden que
 * lo usa, así que lo **libre** es lo disponible menos los kilos ya planeados en órdenes abiertas (pendientes, en curso o
 * en revisión) que todavía no se aplicaron: dos órdenes no pueden planear el mismo saldo. Una orden cancelada ya no
 * reserva nada. Una sola fuente para el selector de la OP, la acción que planea y el listado de saldos.
 *
 * Un saldo puede venir de cualquier producto de la bodega (decisión del 2026-09-23), y al usarlo se mezcla en otro
 * lote: cada uno lleva el producto con el color y el lote de su orden de origen, para que quien lo elige sepa qué es.
 */
class AvailableRemnantsService
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Los saldos que se pueden planear en la orden: todos los de su bodega con kilos libres, de cualquier producto, del
     * más antiguo al más nuevo (para que se gasten primero), desempatado por id: con la misma fecha, el orden sería
     * arbitrario. Sin tope: el selector busca. Una sola fuente para la lista que llega con el detalle de la orden y la
     * que la refresca (`RemnantConsumptionController::availableRemnants`).
     *
     * @return list<array{id: int, source_order_number: string, source_lot_number: int, product_name: string, available_quantity_kg: string}>
     */
    public function forOrder(ProductionOrder $order): array
    {
        $remnants = ProductionRemnant::query()
            ->with(['sourceOrder:id,order_number,lot_number,color', 'product:id,name'])
            ->available()
            ->where('warehouse_id', $order->warehouse_id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $planned = $this->plannedKilos($remnants->modelKeys());

        return $remnants
            ->map(fn (ProductionRemnant $remnant): array => [
                'id' => $remnant->id,
                'source_order_number' => $remnant->sourceOrder->order_number,
                'source_lot_number' => $remnant->sourceOrder->lot_number,
                'product_name' => ProductionOrder::nameWithColor($remnant->product->name, $remnant->sourceOrder->color),
                // Los kilos libres: lo que todavía se puede planear.
                'available_quantity_kg' => $this->free($remnant, $planned[$remnant->id] ?? '0'),
            ])
            ->filter(fn (array $remnant): bool => $this->calculator->isPositive($remnant['available_quantity_kg']))
            ->values()
            ->all();
    }

    /**
     * Los kilos libres de un saldo. Quien planea lo llama con el saldo bloqueado, para que dos órdenes no reserven a la
     * vez lo mismo.
     */
    public function freeKilos(ProductionRemnant $remnant): string
    {
        return $this->free($remnant, $this->plannedKilos([$remnant->id])[$remnant->id] ?? '0');
    }

    /**
     * Kilos planeados y sin aplicar en órdenes abiertas, por saldo, en una sola consulta.
     *
     * @param  list<int>  $remnantIds
     * @return array<int, string> id del saldo => kilos comprometidos (solo los que tienen)
     */
    public function plannedKilos(array $remnantIds): array
    {
        if ($remnantIds === []) {
            return [];
        }

        return RemnantConsumption::query()
            ->planned()
            ->whereIn('remnant_id', $remnantIds)
            ->whereHas('targetOrder', fn ($query) => $query->whereIn('status', ProductionOrderStatus::open()))
            ->groupBy('remnant_id')
            ->selectRaw('remnant_id, SUM(quantity_kg) AS planned_kg')
            ->pluck('planned_kg', 'remnant_id')
            // SQLite suma en coma flotante: se redondea a la escala de la columna (en PostgreSQL ya es exacta).
            ->map(fn (string|int|float $kilos): string => $this->calculator->round($kilos, 4))
            ->all();
    }

    private function free(ProductionRemnant $remnant, string $plannedKg): string
    {
        $free = $this->calculator->sub((string) $remnant->available_quantity_kg, $plannedKg, 4);

        return $this->calculator->isNegative($free) ? '0.0000' : $free;
    }
}
