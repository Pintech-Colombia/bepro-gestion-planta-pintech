<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderConsumptionReason;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderLineAdjustment;
use App\Services\DecimalCalculator;
use App\Support\NumberFormat;
use Illuminate\Validation\ValidationException;

/**
 * La tolerancia de consumo de una OP, en un solo lugar: enviar a revisión, completar, el detalle de la OP y la alerta de
 * sobreconsumo leen de aquí si una materia prima se pasó de lo planeado. La pantalla no repite la cuenta.
 *
 * Por materia prima, en su unidad y dentro de la misma OP: consumido = lo real de todas sus líneas (pasos) + sus ajustes
 * de línea, contra planeado = lo planeado de todas sus líneas. Se pasa si consumido > planeado × (1 + % / 100); justo en
 * el límite no se pasa. Se compara por materia prima y no por línea: 30 kg en un paso y 30 en otro, gastar 60 en el
 * primero y 0 en el segundo no es pasarse. Una materia prima sin nada planeado (solo por ajuste) no se evalúa: el ajuste
 * ya trae su motivo. Un rendimiento menor al planeado no es un error y no se mira aquí.
 *
 * El motivo se pide solo si lo real **sin ajustes** pasa el límite: el exceso que viene de ajustes de línea ya tiene el
 * suyo. La alerta cuenta todo (`exceeds`), porque el costo es real.
 *
 * @phpstan-type ConsumptionRow array{
 *   raw_material_id: int,
 *   code: string|null,
 *   unit: string|null,
 *   planned: string,
 *   consumed: string,
 *   consumed_without_adjustments: string,
 *   limit: string,
 *   tolerance_percentage: string,
 *   excess_percentage: string,
 *   exceeds: bool,
 *   needs_reason: bool
 * }
 */
class ProductionOrderConsumptionCheck
{
    public const REASON_MAX_LENGTH = 500;

    public function __construct(
        private readonly DecimalCalculator $calculator,
    ) {}

    /** El % vigente para las OP que se evalúan ahora (`config/production.php`). */
    public function currentTolerancePercentage(): string
    {
        return $this->calculator->round((string) config('production.consumption_tolerance_percentage'), 2);
    }

    /**
     * Cada materia prima planeada de la orden, con lo consumido y si se pasa.
     *
     * @param  array<int, string>|null  $actualByLine  id de la línea => lo real, ya en la unidad de la materia prima. Sin
     *                                                 él, lo guardado en cada línea (vacío cuenta como 0).
     * @param  string|null  $tolerancePercentage  sin él, el vigente
     * @return list<ConsumptionRow>
     */
    public function evaluate(ProductionOrder $order, ?array $actualByLine = null, ?string $tolerancePercentage = null): array
    {
        $order->loadMissing(['details.rawMaterial.unitOfMeasure', 'lineAdjustments']);

        $tolerance = $tolerancePercentage ?? $this->currentTolerancePercentage();
        $factor = $this->calculator->add('1', $this->calculator->div($tolerance, '100', 8), 8);

        $adjustedByMaterial = [];
        foreach ($order->lineAdjustments as $adjustment) {
            /** @var ProductionOrderLineAdjustment $adjustment */
            $id = (int) $adjustment->raw_material_id;
            $adjustedByMaterial[$id] = $this->calculator->add($adjustedByMaterial[$id] ?? '0', (string) $adjustment->quantity, 4);
        }

        /** @var array<int, array{code: string|null, unit: string|null, planned: string, actual: string}> $materials */
        $materials = [];
        foreach ($order->details as $detail) {
            /** @var ProductionOrderDetail $detail */
            $id = (int) $detail->raw_material_id;
            $actual = $actualByLine !== null && array_key_exists((int) $detail->id, $actualByLine)
                ? (string) $actualByLine[(int) $detail->id]
                : (string) ($detail->actual_quantity ?? '0');

            $materials[$id] ??= [
                'code' => $detail->rawMaterial?->code,
                'unit' => $detail->rawMaterial?->unitOfMeasure?->symbol,
                'planned' => '0',
                'actual' => '0',
            ];
            $materials[$id]['planned'] = $this->calculator->add($materials[$id]['planned'], (string) $detail->planned_quantity, 4);
            $materials[$id]['actual'] = $this->calculator->add($materials[$id]['actual'], $actual, 4);
        }

        $rows = [];
        foreach ($materials as $id => $material) {
            if (! $this->calculator->isPositive($material['planned'])) {
                continue;
            }

            $consumed = $this->calculator->add($material['actual'], $adjustedByMaterial[$id] ?? '0', 4);
            $limit = $this->calculator->mul($material['planned'], $factor, 8);

            $rows[] = [
                'raw_material_id' => $id,
                'code' => $material['code'],
                'unit' => $material['unit'],
                'planned' => $material['planned'],
                'consumed' => $consumed,
                'consumed_without_adjustments' => $material['actual'],
                'limit' => $this->calculator->round($limit, 4),
                'tolerance_percentage' => $tolerance,
                'excess_percentage' => $this->calculator->round(
                    $this->calculator->mul($this->calculator->div($this->calculator->sub($consumed, $material['planned'], 8), $material['planned'], 8), '100', 8),
                    2,
                ),
                'exceeds' => $this->calculator->cmp($consumed, $limit, 8) > 0,
                'needs_reason' => $this->calculator->cmp($material['actual'], $limit, 8) > 0,
            ];
        }

        return $rows;
    }

    /**
     * Lo que la orden muestra con lo guardado: las materias primas que se pasan, con su motivo. Completada, con el % con
     * que se evaluó al completar (una anterior a la tolerancia no lo tiene y no marca nada) y el costo del exceso; en
     * revisión, con el vigente. Pendiente, en curso o cancelada: nada (lo digitado aún no es lo entregado).
     *
     * @return list<ConsumptionRow&array{reason: string|null, extra_cost: string|null}>
     */
    public function forOrder(ProductionOrder $order): array
    {
        $tolerance = match ($order->status) {
            ProductionOrderStatus::Completed => $order->consumption_tolerance_percentage !== null
                ? (string) $order->consumption_tolerance_percentage
                : null,
            ProductionOrderStatus::PendingReview => $this->currentTolerancePercentage(),
            default => null,
        };

        if ($tolerance === null) {
            return [];
        }

        $order->loadMissing('consumptionReasons');
        $reasons = $order->consumptionReasons->pluck('reason', 'raw_material_id');
        $isCompleted = $order->status === ProductionOrderStatus::Completed;

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'reason' => $reasons->get($row['raw_material_id']),
                'extra_cost' => $isCompleted ? $this->extraCost($order, $row) : null,
            ],
            array_filter($this->evaluate($order, null, $tolerance), fn (array $row): bool => $row['exceeds']),
        ));
    }

    /**
     * Enviar a revisión y completar: con lo real ya convertido, 422 si a una materia prima que se pasa le falta el
     * motivo (`overconsumption_reasons.<id de la materia prima>`); si no, guarda los motivos. El de una materia prima
     * que ya no lo necesita se borra. Corre dentro de la transacción de la acción, con la orden bloqueada.
     *
     * @param  array<int|string, array<string, mixed>>  $ingredients  las líneas enviadas, con `actual_quantity` ya en la
     *                                                                unidad de la materia prima (`ProductionOrderLineUnits`)
     * @param  array<int|string, mixed>  $reasons  id de la materia prima => motivo
     *
     * @throws ValidationException si falta un motivo
     */
    public function recordReasons(ProductionOrder $order, array $ingredients, array $reasons, int $userId): void
    {
        $actualByLine = collect($ingredients)
            ->mapWithKeys(fn (array $row): array => [(int) $row['id'] => (string) $row['actual_quantity']])
            ->all();
        $needing = array_values(array_filter($this->evaluate($order, $actualByLine), fn (array $row): bool => $row['needs_reason']));
        $written = [];
        $errors = [];

        foreach ($needing as $row) {
            $reason = trim((string) ($reasons[$row['raw_material_id']] ?? ''));

            if ($reason === '') {
                $errors["overconsumption_reasons.{$row['raw_material_id']}"] = $this->reasonRequiredMessage($row);

                continue;
            }

            $written[$row['raw_material_id']] = $reason;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $existing = $order->consumptionReasons()->get()->keyBy('raw_material_id');

        foreach ($existing as $rawMaterialId => $stored) {
            /** @var ProductionOrderConsumptionReason $stored */
            if (! array_key_exists((int) $rawMaterialId, $written)) {
                // Uno por uno, para que la auditoría registre cada borrado.
                $stored->delete();
            }
        }

        foreach ($written as $rawMaterialId => $reason) {
            $stored = $existing->get($rawMaterialId);

            if ($stored !== null) {
                $stored->update(['reason' => $reason]);

                continue;
            }

            $order->consumptionReasons()->create([
                'raw_material_id' => $rawMaterialId,
                'reason' => $reason,
                'created_by' => $userId,
            ]);
        }

        $order->unsetRelation('consumptionReasons');
    }

    /**
     * Lo que costó el exceso sobre lo planeado: (consumido − planeado) × el costo real por unidad de esa materia prima
     * en la orden (promedio ponderado de sus líneas). Solo con la orden completada, que es cuando las líneas tienen su
     * costo real; null si sus líneas no consumieron nada (todo vino por ajustes).
     *
     * @param  ConsumptionRow  $row
     */
    public function extraCost(ProductionOrder $order, array $row): ?string
    {
        $lines = $order->details->filter(fn (ProductionOrderDetail $detail): bool => (int) $detail->raw_material_id === $row['raw_material_id']);
        $quantity = $this->calculator->sum($lines->map(fn (ProductionOrderDetail $detail): string => (string) ($detail->actual_quantity ?? '0'))->all());

        if (! $this->calculator->isPositive($quantity)) {
            return null;
        }

        $cost = $this->calculator->sum($lines->map(fn (ProductionOrderDetail $detail): string => (string) ($detail->total_cost ?? '0'))->all());
        $unitCost = $this->calculator->div($cost, $quantity, 8);

        return $this->calculator->round(
            $this->calculator->mul($this->calculator->sub($row['consumed'], $row['planned'], 4), $unitCost, 8),
            4,
        );
    }

    /**
     * «S-7: 55,6 kg de 38,65 planeados (+43,9%)».
     *
     * @param  ConsumptionRow  $row
     */
    public function summary(array $row): string
    {
        return sprintf(
            '%s: %s%s de %s planeados (+%s)',
            $row['code'] ?? "MP #{$row['raw_material_id']}",
            NumberFormat::quantity($row['consumed']),
            $row['unit'] !== null ? " {$row['unit']}" : '',
            NumberFormat::quantity($row['planned']),
            $this->excessLabel($row),
        );
    }

    /**
     * El exceso con un decimal: con dos se lee mal, y sin decimales un 10,3 % parecería estar en el límite.
     *
     * @param  ConsumptionRow  $row
     */
    public function excessLabel(array $row): string
    {
        return NumberFormat::percent($this->calculator->round($row['excess_percentage'], 1));
    }

    /** @param  ConsumptionRow  $row */
    private function reasonRequiredMessage(array $row): string
    {
        return __(':summary. Se pasó más del :tolerance de lo planeado: escribe el motivo.', [
            'summary' => $this->summary($row),
            'tolerance' => NumberFormat::percent($row['tolerance_percentage']),
        ]);
    }
}
