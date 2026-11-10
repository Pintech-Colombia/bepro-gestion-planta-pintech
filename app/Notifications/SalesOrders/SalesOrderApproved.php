<?php

declare(strict_types=1);

namespace App\Notifications\SalesOrders;

/**
 * Pedido aprobado: a la planta, que lo convierte en órdenes de producción.
 */
class SalesOrderApproved extends SalesOrderNotification
{
    public function kind(): string
    {
        return 'approved';
    }

    protected function outcome(): string
    {
        return 'aprobado'.$this->partialSuffix();
    }
}
