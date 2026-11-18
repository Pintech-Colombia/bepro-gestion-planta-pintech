<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Enums\RemnantStatus;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\User;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;
use App\Services\Production\RemnantLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da de baja un saldo de PT que ya no sirve (se secó, se dañó, se botó): queda en 0 kg y consumido, con la nota en su
 * historial. No se puede mientras una orden abierta lo tenga planeado: al completarla no encontraría sus kilos.
 */
class WriteOffRemnantAction
{
    /** La clave del error que no es de un campo: el diálogo la muestra sobre la nota. */
    public const ERROR_KEY = 'remnant';

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly RemnantLedger $ledger,
    ) {}

    public function execute(ProductionRemnant $remnant, string $notes, User $user): ProductionRemnantMovement
    {
        return DB::transaction(function () use ($remnant, $notes, $user): ProductionRemnantMovement {
            // Con el saldo bloqueado, una orden que lo planea o lo descuenta a la vez espera, y después ve la baja.
            $lockedRemnant = ProductionRemnant::query()
                ->lockForUpdate()
                ->findOrFail($remnant->id);

            if ($lockedRemnant->status === RemnantStatus::Consumed
                || ! $this->calculator->isPositive((string) $lockedRemnant->available_quantity_kg)) {
                throw ValidationException::withMessages([
                    self::ERROR_KEY => __('Este saldo ya está agotado: no hay nada que dar de baja.'),
                ]);
            }

            $this->availableRemnants->ensureCoversPlanned(
                $lockedRemnant,
                '0',
                self::ERROR_KEY,
                'No se puede dar de baja: lo tienen planeado órdenes abiertas (:orders). Quítalo primero de esas órdenes.',
            );

            return $this->ledger->writeOff($lockedRemnant, $notes, (int) $user->id);
        }, attempts: 3);
    }
}
