<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\RemnantStatus;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;
use App\Services\DecimalCalculator;
use App\Support\NumberFormat;
use Illuminate\Validation\ValidationException;

/**
 * La regla para descontar kilos de un saldo de PT, en un solo lugar: estimar (al planear la línea y en la vista previa
 * de costos) y aplicar (al completar la OP). Los kilos mandan: los galones que quedan se recalculan de los kilos que
 * quedan y los consumidos salen de la resta, así que todos los consumos de un saldo suman exactamente sus galones
 * originales y el costo se traslada completo. Un resto de menos de 0,0001 gal no se puede usar: agota el saldo con
 * todos los kilos que había.
 */
class RemnantDrawdown
{
    /** La clave del error al completar: la lista de saldos que envía la pantalla. */
    public const APPLY_ERROR_KEY = 'remnant_consumption_ids';

    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Lo que pasaría si se descontaran `$quantityKg` del saldo tal como está ahora, sin escribir nada. `quantity_gallons`
     * puede ser 0 si la cantidad es tan pequeña que no alcanza 0,0001 gal; quien llama decide qué hacer.
     *
     * @return array{quantity_kg: string, quantity_gallons: string, consumed_cost: ?string, remaining_kg: string, remaining_gallons: string, exhausted: bool}
     */
    public function estimate(ProductionRemnant $remnant, string $quantityKg): array
    {
        $availableKg = (string) $remnant->available_quantity_kg;
        $remainingKg = $this->calculator->sub($availableKg, $quantityKg, 4);
        $remainingGallons = $this->calculator->div($remainingKg, (string) $remnant->density_kg_per_gallon, 4);

        $exhausted = $this->calculator->isZero($remainingGallons);

        if ($exhausted) {
            $quantityKg = $availableKg;
            $remainingKg = '0';
            $remainingGallons = '0';
        }

        $consumedGallons = $this->calculator->sub((string) $remnant->available_quantity_gallons, $remainingGallons, 4);

        $consumedCost = $remnant->cost_per_gallon !== null
            ? $this->calculator->mul($consumedGallons, (string) $remnant->cost_per_gallon, 4)
            : null;

        return [
            'quantity_kg' => $this->calculator->round($quantityKg, 4),
            'quantity_gallons' => $consumedGallons,
            'consumed_cost' => $consumedCost,
            'remaining_kg' => $remainingKg,
            'remaining_gallons' => $remainingGallons,
            'exhausted' => $exhausted,
        ];
    }

    /**
     * El costo de una línea: el real si ya se aplicó, o el estimado si está planeada (con el saldo como está ahora).
     * Nulo si el saldo no tiene costo, o si la línea está planeada y el saldo ya no está disponible o no le alcanza: nunca
     * un estimado contra un saldo que no cubre la línea. Lo usan la vista previa de costos y el detalle de la orden.
     */
    public function lineCost(RemnantConsumption $line): ?string
    {
        if ($line->isApplied()) {
            return $line->consumed_cost !== null ? (string) $line->consumed_cost : null;
        }

        if (! $this->covers($line->remnant, (string) $line->quantity_kg)) {
            return null;
        }

        return $this->estimate($line->remnant, (string) $line->quantity_kg)['consumed_cost'];
    }

    /**
     * Descuenta una línea planeada del saldo, ya bloqueado por quien llama, y la marca como aplicada con los kilos,
     * galones y costo reales. 422 si el saldo ya no alcanza para los kilos planeados.
     */
    public function apply(RemnantConsumption $line, ProductionRemnant $lockedRemnant): void
    {
        $plannedKg = (string) $line->quantity_kg;
        $availableKg = (string) $lockedRemnant->available_quantity_kg;

        $drawdown = $this->covers($lockedRemnant, $plannedKg) ? $this->estimate($lockedRemnant, $plannedKg) : null;

        if ($drawdown === null || ! $this->calculator->isPositive($drawdown['quantity_gallons'])) {
            throw ValidationException::withMessages([
                self::APPLY_ERROR_KEY => __('El saldo de :order ya no alcanza: quedan :available kg y la orden planeó :planned kg. Quita la línea y vuelve a agregarla con lo que queda.', [
                    'order' => $lockedRemnant->sourceOrder?->order_number,
                    'available' => NumberFormat::quantity($availableKg),
                    'planned' => NumberFormat::quantity($plannedKg),
                ]),
            ]);
        }

        $lockedRemnant->update([
            'available_quantity_gallons' => $drawdown['remaining_gallons'],
            'available_quantity_kg' => $drawdown['remaining_kg'],
            'status' => $drawdown['exhausted'] ? RemnantStatus::Consumed : RemnantStatus::PartiallyConsumed,
        ]);

        $line->update([
            'quantity_kg' => $drawdown['quantity_kg'],
            'quantity_gallons' => $drawdown['quantity_gallons'],
            'consumed_cost' => $drawdown['consumed_cost'],
            'applied_at' => now(),
        ]);
    }

    /**
     * Si el saldo sigue disponible y sus kilos alcanzan para la línea.
     */
    private function covers(ProductionRemnant $remnant, string $quantityKg): bool
    {
        return in_array($remnant->status, [RemnantStatus::Available, RemnantStatus::PartiallyConsumed], true)
            && $this->calculator->cmp($quantityKg, (string) $remnant->available_quantity_kg, 4) <= 0;
    }
}
