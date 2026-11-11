<?php

declare(strict_types=1);

namespace App\Notifications\SalesOrders;

use App\Models\SalesOrder;
use Illuminate\Notifications\Notification;

/**
 * Aviso personal sobre un pedido, en la campana del encabezado. Sin cola: insertar la fila es barato y la cola sumaría
 * una forma de fallar. El correo se agrega después en `via()`.
 *
 * El mensaje se guarda ya armado, con el cliente de ese momento: es lo que se lee en la campana aunque el pedido cambie.
 */
abstract class SalesOrderNotification extends Notification
{
    /**
     * @param  SalesOrder|null  $remainder  En una aprobación parcial, el pedido nuevo con lo no aprobado.
     */
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly ?SalesOrder $remainder = null,
    ) {}

    /**
     * Tipo del aviso, guardado en `data.kind`.
     */
    abstract public function kind(): string;

    /**
     * Lo que dice el aviso después de «Pedido #N de {cliente}».
     */
    abstract protected function outcome(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{sales_order_id: int, kind: string, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'sales_order_id' => $this->order->id,
            'kind' => $this->kind(),
            'message' => $this->message(),
        ];
    }

    public function message(): string
    {
        $client = $this->order->client_business_name ?? $this->order->client?->business_name;
        $subject = $client !== null && $client !== '' ? "Pedido #{$this->order->id} de {$client}" : "Pedido #{$this->order->id}";

        // El resto de una aprobación parcial dice de qué pedido salió: «Pedido #21 de X (resto del #20) retenido».
        if ($this->order->parent_sales_order_id !== null) {
            $subject .= " (resto del #{$this->order->parent_sales_order_id})";
        }

        return "{$subject} {$this->outcome()}";
    }

    /**
     * «parcial (resto en el #N): {nota}» de una aprobación parcial, para los avisos de aprobado y liberado.
     */
    protected function partialSuffix(): string
    {
        if (! $this->order->is_partial_approval) {
            return '';
        }

        $where = $this->remainder !== null ? " (resto en el #{$this->remainder->id})" : '';

        return " parcial{$where}: {$this->order->approval_note}";
    }
}
