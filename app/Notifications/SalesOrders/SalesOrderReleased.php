<?php

declare(strict_types=1);

namespace App\Notifications\SalesOrders;

/**
 * Pedido retenido que se aprobó después: a quien lo creó, que supo que estaba retenido. Uno aprobado de una vez no le
 * avisa.
 */
class SalesOrderReleased extends SalesOrderNotification
{
    public function kind(): string
    {
        return 'released';
    }

    protected function outcome(): string
    {
        return 'liberado'.$this->partialSuffix();
    }
}
