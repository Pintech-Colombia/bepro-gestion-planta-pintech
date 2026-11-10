<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estados de la aprobación de pedidos: todo pedido nuevo nace «Por aprobar» y puede quedar «Retenido».
 *
 * En PostgreSQL el CHECK se cambia a mano: `->change()` sobre un enum genera `alter column ... type varchar(255) check
 * (...)`, que no es SQL válido. En SQLite `->change()` reconstruye la tabla con el CHECK; va después de la migración de
 * columnas porque agregar una clave foránea también reconstruye la tabla y, al copiarla, pierde los CHECK. Los pedidos
 * que ya existen se quedan en su estado.
 */
return new class extends Migration
{
    private const CHECK = 'sales_orders_status_check';

    private const STATUSES = ['pending_approval', 'on_hold', 'pending', 'in_progress', 'ready', 'delivered', 'cancelled'];

    private const PREVIOUS_STATUSES = ['pending', 'in_progress', 'ready', 'delivered', 'cancelled'];

    public function up(): void
    {
        $this->setStatuses(self::STATUSES, 'pending_approval');
    }

    /**
     * Falla si ya hay pedidos «Por aprobar» o «Retenido»: el CHECK anterior no los admite, y está bien que falle.
     */
    public function down(): void
    {
        $this->setStatuses(self::PREVIOUS_STATUSES, 'pending');
    }

    /**
     * @param  list<string>  $statuses
     */
    private function setStatuses(array $statuses, string $default): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $allowed = implode(', ', array_map(fn (string $status): string => "'{$status}'", $statuses));

            DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT '.self::CHECK);
            DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT '.self::CHECK." CHECK (status IN ({$allowed}))");
            DB::statement("ALTER TABLE sales_orders ALTER COLUMN status SET DEFAULT '{$default}'");

            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) use ($statuses, $default): void {
            $table->enum('status', $statuses)->default($default)->change();
        });
    }
};
