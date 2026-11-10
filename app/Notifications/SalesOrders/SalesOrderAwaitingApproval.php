<?php

declare(strict_types=1);

namespace App\Notifications\SalesOrders;

/**
 * Pedido nuevo (directo o desde una cotización): a quien recibe los avisos de pedidos por aprobar.
 */
class SalesOrderAwaitingApproval extends SalesOrderNotification
{
    public function kind(): string
    {
        return 'awaiting_approval';
    }

    protected function outcome(): string
    {
        return 'por aprobar';
    }
}
