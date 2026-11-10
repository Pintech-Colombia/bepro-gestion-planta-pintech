<?php

declare(strict_types=1);

namespace App\Actions\SalesOrders;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parte un pedido al aprobarlo parcial: lo aprobado se queda en él y lo demás pasa a un pedido nuevo «Retenido»,
 * enlazado al original («Resto del pedido #N»), con el mismo cliente y el mismo creador, y la nota como motivo.
 *
 * Una línea con 0 aprobado se mueve al pedido nuevo; una con parte aprobada se reduce y su diferencia se crea en el
 * nuevo. Nunca se borra una línea: por cada producto, lo del original más lo del resto es lo pedido. Solo la llama
 * `ApproveSalesOrderAction`, dentro de su transacción y con el pedido ya bloqueado.
 */
class SplitSalesOrderRemainder
{
    public const string NOTHING_APPROVED = 'Aprueba al menos una unidad. Si no sale nada, retén el pedido.';

    public const string NOTHING_LEFT = 'Apruebas todo lo pedido: quita la marca de aprobación parcial.';

    public const string LINES_MISMATCH = 'Indica cuánto se aprueba de cada producto del pedido.';

    /**
     * @param  array<int, int>  $approvedQuantities  Unidades que salen ahora, por id de línea; una entrada por línea.
     */
    public function split(SalesOrder $lockedOrder, User $user, string $reason, array $approvedQuantities): SalesOrder
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('SplitSalesOrderRemainder::split debe llamarse dentro de una transacción.');
        }

        // Las líneas también se bloquean antes de leerlas: sus cantidades deciden qué pasa al resto.
        $items = SalesOrderItem::query()
            ->where('sales_order_id', $lockedOrder->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->ensureValid($items, $approvedQuantities);

        $remainder = SalesOrder::create([
            'client_id' => $lockedOrder->client_id,
            'status' => SalesOrderStatus::OnHold,
            'priority' => $lockedOrder->priority,
            'required_date' => $lockedOrder->required_date,
            'notes' => $lockedOrder->notes,
            'shipping_address' => $lockedOrder->shipping_address,
            'client_business_name' => $lockedOrder->client_business_name,
            'client_nit' => $lockedOrder->client_nit,
            'client_contact_name' => $lockedOrder->client_contact_name,
            'client_phone' => $lockedOrder->client_phone,
            // Mismo creador: recibe los avisos de retenido y liberado, y lo ve con `view_own`. La cotización no se
            // copia (su enlace es único): se llega a ella por el pedido original.
            'created_by' => $lockedOrder->created_by,
            'held_by' => $user->id,
            'held_at' => now(),
            'hold_reason' => $reason,
            'parent_sales_order_id' => $lockedOrder->id,
        ]);

        foreach ($items as $item) {
            $approved = $approvedQuantities[$item->id];

            if ($approved === $item->quantity) {
                continue;
            }

            if ($approved === 0) {
                $item->update(['sales_order_id' => $remainder->id]);

                continue;
            }

            $remainder->items()->create([
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity - $approved,
                'color' => $item->color,
            ]);
            $item->update(['quantity' => $approved]);
        }

        return $remainder;
    }

    /**
     * El request ya lo validó; aquí se vuelve a revisar con las líneas bloqueadas, por si la acción se llama sin él.
     *
     * @param  Collection<int, SalesOrderItem>  $items
     * @param  array<int, int>  $approvedQuantities
     */
    private function ensureValid(Collection $items, array $approvedQuantities): void
    {
        $lineIds = $items->modelKeys();
        $sentIds = array_keys($approvedQuantities);
        sort($lineIds);
        sort($sentIds);

        if ($lineIds === [] || $lineIds !== $sentIds) {
            throw ValidationException::withMessages(['items' => self::LINES_MISMATCH]);
        }

        $approvedTotal = 0;
        $remainderTotal = 0;

        foreach ($items as $item) {
            $approved = $approvedQuantities[$item->id];

            if ($approved < 0 || $approved > $item->quantity) {
                throw ValidationException::withMessages(['items' => self::LINES_MISMATCH]);
            }

            $approvedTotal += $approved;
            $remainderTotal += $item->quantity - $approved;
        }

        if ($approvedTotal === 0) {
            throw ValidationException::withMessages(['items' => self::NOTHING_APPROVED]);
        }

        if ($remainderTotal === 0) {
            throw ValidationException::withMessages(['items' => self::NOTHING_LEFT]);
        }
    }
}
