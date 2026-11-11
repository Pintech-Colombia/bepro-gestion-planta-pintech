<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido del que salió un resto: al aprobar parcial, lo no aprobado pasa a un pedido nuevo retenido, enlazado al
 * original. `restrictOnDelete`: el historial del pedido original no se pierde mientras exista su resto.
 *
 * En SQLite, agregar o quitar una clave foránea reconstruye la tabla y la copia pierde los CHECK declarados en el
 * `CREATE TABLE`; el de estados se vuelve a crear con `->change()`, como en la migración de los estados de aprobación.
 * En PostgreSQL el CHECK no se toca.
 */
return new class extends Migration
{
    private const STATUSES = ['pending_approval', 'on_hold', 'pending', 'in_progress', 'ready', 'delivered', 'cancelled'];

    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            // Único: un pedido tiene a lo sumo un resto (aprobado, queda «Pendiente» y no se vuelve a aprobar).
            $table->foreignId('parent_sales_order_id')->nullable()->unique()->constrained('sales_orders')->restrictOnDelete();
        });

        $this->restoreSqliteStatusCheck();
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['parent_sales_order_id']);
            $table->dropUnique(['parent_sales_order_id']);
            $table->dropColumn('parent_sales_order_id');
        });

        $this->restoreSqliteStatusCheck();
    }

    private function restoreSqliteStatusCheck(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->enum('status', self::STATUSES)->default('pending_approval')->change();
        });
    }
};
