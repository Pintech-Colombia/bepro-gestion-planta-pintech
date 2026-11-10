<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién decidió sobre el pedido y cuándo: aprobado (con nota y casilla de parcial) o retenido (con motivo). Al aprobar
 * un pedido retenido, los datos de la retención se conservan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->boolean('is_partial_approval')->default(false);
            $table->foreignId('held_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('held_at')->nullable();
            $table->text('hold_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['held_by']);
            $table->dropColumn(['approved_by', 'approved_at', 'approval_note', 'is_partial_approval', 'held_by', 'held_at', 'hold_reason']);
        });
    }
};
