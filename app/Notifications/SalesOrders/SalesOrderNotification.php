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
    public function __construct(protected readonly SalesOrder $order) {}

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

        return "{$subject} {$this->outcome()}";
    }

    /**
     * «parcial: {nota}» de una aprobación parcial, para los avisos de aprobado y liberado.
     */
    protected function partialSuffix(): string
    {
        return $this->order->is_partial_approval ? " parcial: {$this->order->approval_note}" : '';
    }
}
