<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\RemnantMovementType;
use App\Enums\RemnantStatus;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\RemnantConsumption;
use App\Services\DecimalCalculator;

/**
 * La única pieza que cambia los kilos de un saldo de PT, y cada cambio deja su movimiento en el historial
 * (`production_remnant_movements`): nacer al completar su OP, consumirse al completar otra, darse de baja, corregirse y
 * recibir lo que quedó en un envase abierto de un lote de su OP.
 * Así ningún camino mueve kilos sin rastro. Las reglas (cuánto se descuenta, qué se permite) viven en quien llama
 * (`RemnantDrawdown`, las acciones de baja y corrección); aquí solo se escribe, dentro de la transacción y con el saldo
 * ya bloqueado por quien llama.
 */
class RemnantLedger
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Crea el saldo que quedó al completar su OP, con todos sus kilos disponibles, y su movimiento de nacimiento.
     *
     * @param  array<string, mixed>  $attributes  las columnas del saldo; `source_order_id` y `original_quantity_kg` obligatorias
     */
    public function born(array $attributes, int $userId): ProductionRemnant
    {
        $remnant = ProductionRemnant::create($attributes);

        $this->record($remnant, RemnantMovementType::Birth, (string) $remnant->original_quantity_kg, [
            'production_order_id' => $remnant->source_order_id,
            'user_id' => $userId,
        ]);

        return $remnant;
    }

    /**
     * Lo que quedó, pesado, en un envase abierto de un lote vuelve al saldo de la OP que fabricó ese lote. Con el saldo
     * ya bloqueado por quien llama, se suman los kilos, los galones quedan en `$availableGallonsAfter` (los de los kilos
     * que quedan, calculados por quien llama con la regla del saldo) y el estado se recalcula: uno consumido o dado de
     * baja vuelve a tener kilos. Si la OP de origen no tiene saldo, nace aquí con esos kilos disponibles y lo **original en
     * 0** (`$newRemnant`, las columnas del saldo nuevo): lo original es lo que su OP dejó de saldo al completarse, y el
     * resumen de costos de esa OP, ya cerrada, lo lee así; esos kilos los explica el movimiento. El movimiento es «Cuñete
     * abierto», con la OP que abrió el envase y la fuente de lote.
     *
     * @param  array<string, mixed>  $newRemnant  las columnas del saldo si hay que crearlo
     */
    public function returnFromOpenedUnit(
        ?ProductionRemnant $lockedRemnant,
        array $newRemnant,
        string $quantityKg,
        string $availableGallonsAfter,
        ProductionOrderBatchConsumption $source,
        int $userId,
    ): ProductionRemnant {
        if ($lockedRemnant === null) {
            $remnant = ProductionRemnant::create([
                ...$newRemnant,
                'original_quantity_kg' => '0',
                'original_quantity_gallons' => '0',
                'available_quantity_kg' => $quantityKg,
                'available_quantity_gallons' => $availableGallonsAfter,
                'status' => RemnantStatus::Available,
            ]);
        } else {
            $remnant = $lockedRemnant;
            $remnant->update([
                'available_quantity_kg' => $this->calculator->add((string) $remnant->available_quantity_kg, $quantityKg, 4),
                'available_quantity_gallons' => $availableGallonsAfter,
                'status' => $this->restockedStatus($remnant),
            ]);
        }

        $this->record($remnant, RemnantMovementType::OpenedUnit, $quantityKg, [
            'production_order_id' => $source->production_order_id,
            'production_order_batch_consumption_id' => $source->id,
            'user_id' => $userId,
        ]);

        return $remnant;
    }

    /**
     * El estado de un saldo que vuelve a tener kilos sin que nadie lo haya consumido ahora (corregir, recibir un envase
     * abierto): disponible si ninguna orden lo usó, parcialmente consumido si alguna sí. Nunca queda consumido.
     */
    public function restockedStatus(ProductionRemnant $lockedRemnant): RemnantStatus
    {
        return $lockedRemnant->consumptions()->applied()->exists()
            ? RemnantStatus::PartiallyConsumed
            : RemnantStatus::Available;
    }

    /**
     * Descuenta del saldo bloqueado una línea que se aplica al completar su OP, con lo que ya calculó `RemnantDrawdown`.
     */
    public function consume(
        ProductionRemnant $lockedRemnant,
        RemnantConsumption $line,
        string $consumedKg,
        string $remainingKg,
        string $remainingGallons,
        bool $exhausted,
        int $userId,
    ): void {
        $lockedRemnant->update([
            'available_quantity_gallons' => $remainingGallons,
            'available_quantity_kg' => $remainingKg,
            'status' => $exhausted ? RemnantStatus::Consumed : RemnantStatus::PartiallyConsumed,
        ]);

        $this->record($lockedRemnant, RemnantMovementType::Consumption, $consumedKg, [
            'production_order_id' => $line->target_order_id,
            'remnant_consumption_id' => $line->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Da de baja el saldo bloqueado: lo deja en 0 kg y consumido. El historial dice que fue una baja y no un uso.
     */
    public function writeOff(ProductionRemnant $lockedRemnant, string $notes, int $userId): ProductionRemnantMovement
    {
        $writtenOffKg = (string) $lockedRemnant->available_quantity_kg;

        $lockedRemnant->update([
            'available_quantity_gallons' => '0',
            'available_quantity_kg' => '0',
            'status' => RemnantStatus::Consumed,
        ]);

        return $this->record($lockedRemnant, RemnantMovementType::WriteOff, $writtenOffKg, [
            'notes' => $notes,
            'user_id' => $userId,
        ]);
    }

    /**
     * Deja el saldo bloqueado en los kilos que se pesaron. El movimiento guarda la diferencia con signo.
     */
    public function correct(
        ProductionRemnant $lockedRemnant,
        string $quantityKg,
        string $quantityGallons,
        RemnantStatus $status,
        string $notes,
        int $userId,
    ): ProductionRemnantMovement {
        $difference = $this->calculator->sub($quantityKg, (string) $lockedRemnant->available_quantity_kg, 4);

        $lockedRemnant->update([
            'available_quantity_gallons' => $quantityGallons,
            'available_quantity_kg' => $quantityKg,
            'status' => $status,
        ]);

        return $this->record($lockedRemnant, RemnantMovementType::Correction, $difference, [
            'notes' => $notes,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(
        ProductionRemnant $remnant,
        RemnantMovementType $type,
        string $quantityKg,
        array $attributes,
    ): ProductionRemnantMovement {
        return ProductionRemnantMovement::create([
            'production_remnant_id' => $remnant->id,
            'type' => $type,
            'quantity_kg' => $this->calculator->round($quantityKg, 4),
            'balance_after_kg' => $this->calculator->round((string) $remnant->available_quantity_kg, 4),
            ...$attributes,
        ]);
    }
}
