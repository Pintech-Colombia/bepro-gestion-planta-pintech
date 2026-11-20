<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Enums\NumberType;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;
use App\Services\Production\RemnantDrawdown;
use App\Services\Production\RemnantLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deja un saldo de PT en los kilos que se pesaron (para arriba o para abajo), con la nota en su historial. Se escriben
 * los kilos reales, no la diferencia: el movimiento la guarda con signo. Los galones se recalculan con el peso por galón
 * del saldo y el costo por galón no cambia. A 0 kg no se corrige (para eso está la baja) ni por debajo de lo planeado en
 * órdenes abiertas. También se corrige un saldo agotado que estaba mal pesado.
 */
class CorrectRemnantAction
{
    public const ERROR_KEY = 'quantity_kg';

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly RemnantDrawdown $drawdown,
        private readonly RemnantLedger $ledger,
    ) {}

    public function execute(ProductionRemnant $remnant, string $quantityKg, string $notes, User $user): ProductionRemnantMovement
    {
        return DB::transaction(function () use ($remnant, $quantityKg, $notes, $user): ProductionRemnantMovement {
            // Con el saldo bloqueado, una orden que lo descuenta a la vez espera y después parte de los kilos corregidos.
            $lockedRemnant = ProductionRemnant::query()
                ->lockForUpdate()
                ->findOrFail($remnant->id);

            // Corregir un saldo agotado le devuelve kilos: no en una bodega inactiva, que se pudo desactivar justo porque ya
            // no tenía saldos (`DeactivationGuardService::warehouseBlocker`). Bloqueo compartido: una desactivación a la vez
            // espera y ve el saldo.
            $warehouseIsActive = (bool) Warehouse::query()
                ->whereKey($lockedRemnant->warehouse_id)
                ->sharedLock()
                ->value('is_active');

            if (! $warehouseIsActive) {
                throw ValidationException::withMessages([
                    self::ERROR_KEY => __('La bodega del saldo está inactiva: reactívala antes de corregir sus kilos.'),
                ]);
            }

            $quantityKg = $this->calculator->round($quantityKg, 4);

            if ($this->calculator->cmp($quantityKg, (string) $lockedRemnant->available_quantity_kg, 4) === 0) {
                throw ValidationException::withMessages([
                    self::ERROR_KEY => __('El saldo ya tiene esos kilos: no hay nada que corregir.'),
                ]);
            }

            $quantityGallons = $this->drawdown->gallonsFor($lockedRemnant, $quantityKg);

            // Los mismos límites que al descontar: un resto de menos de 0,0001 gal no se puede usar.
            if (! $this->calculator->isPositive($quantityGallons)) {
                throw ValidationException::withMessages([
                    self::ERROR_KEY => __('Son tan pocos kilos que el saldo no se podría usar. Si ya no queda nada, dalo de baja.'),
                ]);
            }

            NumberType::Quantity->ensureFits($quantityGallons, self::ERROR_KEY, __('Los kilos son demasiados para este saldo.'));

            $this->availableRemnants->ensureCoversPlanned(
                $lockedRemnant,
                $quantityKg,
                self::ERROR_KEY,
                'No puede quedar con menos de :planned kg: lo tienen planeado órdenes abiertas (:orders).',
            );

            return $this->ledger->correct(
                $lockedRemnant,
                $quantityKg,
                $quantityGallons,
                $this->ledger->restockedStatus($lockedRemnant),
                $notes,
                (int) $user->id,
            );
        }, attempts: 3);
    }
}
