<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;
use App\Support\NumberFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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
     * arbitrario. Sin tope: el selector busca. La lista llega con el detalle de la orden y la usa también el aviso de
     * faltantes al crearla (`ProductLineShortages`).
     *
     * @return list<array{id: int, product_id: int, source_order_number: string, source_lot_number: int, product_name: string, available_quantity_kg: string}>
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
                // El buscador de una línea de «Productos a usar» solo ofrece fuentes de su producto.
                'product_id' => (int) $remnant->product_id,
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

        return $this->plannedLines($remnantIds)
            ->groupBy('remnant_id')
            ->selectRaw('remnant_id, SUM(quantity_kg) AS planned_kg')
            ->pluck('planned_kg', 'remnant_id')
            // SQLite suma en coma flotante: se redondea a la escala de la columna (en PostgreSQL ya es exacta).
            ->map(fn (string|int|float $kilos): string => $this->calculator->round($kilos, 4))
            ->all();
    }

    /**
     * Que el saldo, bloqueado por quien llama, no quede con menos kilos que los planeados en órdenes abiertas: si no, una
     * de ellas no podría completarse. 422 en `$errorKey` con `$message`, que recibe `:planned` (kilos) y `:orders`
     * (las órdenes con lo suyo). Lo usan la baja (a 0 kg) y la corrección.
     */
    public function ensureCoversPlanned(ProductionRemnant $lockedRemnant, string $quantityKg, string $errorKey, string $message): void
    {
        $orders = $this->plannedOrders([$lockedRemnant->id])[$lockedRemnant->id] ?? [];
        $plannedKg = $this->plannedTotal($orders);

        if ($plannedKg === null || $this->calculator->cmp($quantityKg, $plannedKg, 4) >= 0) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => __($message, [
                'planned' => NumberFormat::quantity($plannedKg),
                'orders' => collect($orders)
                    ->map(fn (array $order): string => $order['order_number'].' ('.NumberFormat::quantity($order['quantity_kg']).' kg)')
                    ->implode(', '),
            ]),
        ]);
    }

    /**
     * Las órdenes abiertas que tienen kilos planeados y sin aplicar de cada saldo, con cuántos, de la más antigua a la más
     * nueva, en una sola consulta. Lo usan la baja y la corrección (no se baja de lo planeado y el error nombra las OP) y
     * sus diálogos.
     *
     * @param  list<int>  $remnantIds
     * @return array<int, list<array{order_id: int, order_number: string, quantity_kg: string}>> id del saldo => órdenes
     */
    public function plannedOrders(array $remnantIds): array
    {
        if ($remnantIds === []) {
            return [];
        }

        $planned = [];

        $this->plannedLines($remnantIds)
            ->join('production_orders', 'production_orders.id', '=', 'remnant_consumptions.target_order_id')
            ->groupBy('remnant_consumptions.remnant_id', 'production_orders.id', 'production_orders.order_number')
            ->orderBy('production_orders.id')
            ->selectRaw('remnant_consumptions.remnant_id, production_orders.id AS order_id, production_orders.order_number, SUM(remnant_consumptions.quantity_kg) AS planned_kg')
            ->get()
            ->each(function (RemnantConsumption $row) use (&$planned): void {
                $planned[(int) $row->getAttribute('remnant_id')][] = [
                    'order_id' => (int) $row->getAttribute('order_id'),
                    'order_number' => (string) $row->getAttribute('order_number'),
                    'quantity_kg' => $this->calculator->round($row->getAttribute('planned_kg'), 4),
                ];
            });

        return $planned;
    }

    /**
     * La suma de lo planeado en las órdenes de un saldo (`plannedOrders`), o null si no hay nada planeado. La única suma
     * de esa lista: la usan la regla de la baja y la corrección, y las pantallas de saldos.
     *
     * @param  list<array{order_id: int, order_number: string, quantity_kg: string}>  $orders
     */
    public function plannedTotal(array $orders): ?string
    {
        return $orders === [] ? null : $this->calculator->sum(array_column($orders, 'quantity_kg'), 4);
    }

    /**
     * Las líneas planeadas y sin aplicar de órdenes abiertas: una orden cancelada o completada ya no reserva nada. La
     * única definición de «comprometido» para las sumas por saldo y por orden.
     *
     * @param  list<int>  $remnantIds
     * @return Builder<RemnantConsumption>
     */
    private function plannedLines(array $remnantIds): Builder
    {
        return RemnantConsumption::query()
            ->planned()
            ->whereIn('remnant_consumptions.remnant_id', $remnantIds)
            ->whereHas('targetOrder', fn ($query) => $query->whereIn('status', ProductionOrderStatus::open()));
    }

    private function free(ProductionRemnant $remnant, string $plannedKg): string
    {
        $free = $this->calculator->sub((string) $remnant->available_quantity_kg, $plannedKg, 4);

        return $this->calculator->isNegative($free) ? '0.0000' : $free;
    }
}
