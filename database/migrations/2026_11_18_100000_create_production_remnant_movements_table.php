<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El libro de cada saldo de PT: nacimiento, consumo en una OP, baja y corrección. Como los movimientos de inventario,
     * no se edita ni se borra; un error se corrige con otro movimiento y una nota. Los kilos van sin signo salvo en la
     * corrección (el tipo dice hacia dónde van) y `balance_after_kg` guarda lo que quedó después de cada uno.
     *
     * El tipo es texto y no un enum de la base: agregar un tipo después no obliga a rehacer una restricción CHECK.
     * La imagen anterior no conoce la tabla y sigue funcionando sin ella.
     */
    public function up(): void
    {
        Schema::create('production_remnant_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_remnant_id')->constrained('production_remnants')->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('quantity_kg', 12, 4);
            $table->decimal('balance_after_kg', 12, 4);
            // La OP donde nació o donde se usó; nula en la baja y la corrección.
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('remnant_consumption_id')->nullable()->constrained('remnant_consumptions')->restrictOnDelete();
            $table->text('notes')->nullable();
            // Nulo solo en el ajuste que deja el relleno del historial cuando los kilos no cuadran: no lo hizo nadie.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['production_remnant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_remnant_movements');
    }
};
