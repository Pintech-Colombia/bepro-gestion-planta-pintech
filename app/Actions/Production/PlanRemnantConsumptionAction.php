<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Enums\RemnantStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;
use App\Models\User;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;
use App\Services\Production\ProductionOrderLock;
use App\Services\Production\RemnantDrawdown;
use App\Support\NumberFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanRemnantConsumptionAction
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductionOrderLock $orderLock,
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly RemnantDrawdown $drawdown,
    ) {}

    /**
     * Planea el uso de un saldo de PT en una OP abierta: crea la línea sin tocar el saldo, que se descuenta al completar
     * la orden. Los kilos no pueden pasar de lo libre del saldo (lo disponible menos lo ya planeado en órdenes abiertas).
     * Se guarda un estimado de los galones; los reales y el costo se calculan al completar.
     */
    public function execute(
        ProductionRemnant $remnant,
        ProductionOrder $targetOrder,
        string $quantityKg,
        User $user,
        ?string $notes = null
    ): RemnantConsumption {
        return DB::transaction(function () use ($remnant, $targetOrder, $quantityKg, $user, $notes): RemnantConsumption {
            // Siempre la OP primero y después los saldos (completar bloquea igual, con los saldos por id ascendente), y
            // una sola OP por transacción: así nadie espera en el orden contrario y no hay abrazo mortal.
            $lockedOrder = $this->orderLock->lockFor(
                $targetOrder,
                $user,
                'planRemnant',
                "No se puede agregar un saldo a una orden en estado ':status'.",
            );

            // Con el saldo bloqueado, otra orden que lo planea a la vez espera y después ve esta línea en lo comprometido.
            $lockedRemnant = ProductionRemnant::query()
                ->lockForUpdate()
                ->findOrFail($remnant->id);

            $this->validate($lockedRemnant, $lockedOrder, $quantityKg);

            $estimate = $this->drawdown->estimate($lockedRemnant, $quantityKg);

            if (! $this->calculator->isPositive($estimate['quantity_gallons'])) {
                throw ValidationException::withMessages([
                    'quantity_kg' => __('La cantidad es demasiado pequeña para registrarla.'),
                ]);
            }

            return RemnantConsumption::create([
                'remnant_id' => $lockedRemnant->id,
                'target_order_id' => $lockedOrder->id,
                // Los kilos digitados: si al completar agotan el saldo por un resto mínimo, ahí se ajustan.
                'quantity_kg' => $quantityKg,
                'quantity_gallons' => $estimate['quantity_gallons'],
                'consumed_cost' => null,
                'consumed_by' => $user->id,
                'consumed_at' => now(),
                'applied_at' => null,
                'notes' => $notes,
            ]);
        }, attempts: 3);
    }

    private function validate(ProductionRemnant $remnant, ProductionOrder $targetOrder, string $quantityKg): void
    {
        if (! in_array($remnant->status, [RemnantStatus::Available, RemnantStatus::PartiallyConsumed], true)) {
            throw ValidationException::withMessages([
                'remnant_id' => __('Este saldo ya fue consumido completamente.'),
            ]);
        }

        if ((int) $remnant->warehouse_id !== (int) $targetOrder->warehouse_id) {
            throw ValidationException::withMessages([
                'remnant_id' => __('El saldo debe estar en la misma bodega que la orden destino.'),
            ]);
        }

        if ($this->calculator->cmp($quantityKg, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'quantity_kg' => __('La cantidad a usar debe ser mayor a cero.'),
            ]);
        }

        $freeKg = $this->availableRemnants->freeKilos($remnant);

        if ($this->calculator->cmp($quantityKg, $freeKg, 4) > 0) {
            throw ValidationException::withMessages([
                'quantity_kg' => __('No hay suficiente saldo libre. Libre: :available kg.', [
                    'available' => NumberFormat::quantity($freeKg),
                ]),
            ]);
        }
    }
}
