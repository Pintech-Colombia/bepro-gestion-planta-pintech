<?php

declare(strict_types=1);

namespace App\Notifications\SalesOrders;

/**
 * Pedido retenido: a quien lo creó, con el motivo.
 */
class SalesOrderOnHold extends SalesOrderNotification
{
    public function kind(): string
    {
        return 'on_hold';
    }

    protected function outcome(): string
    {
        return "retenido: {$this->order->hold_reason}";
    }
}
