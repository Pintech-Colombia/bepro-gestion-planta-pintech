<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\ProductionOrder;
use App\Services\AlertService;
use App\Services\DecimalCalculator;
use App\Support\NumberFormat;

/**
 * La alerta de sobreconsumo de una OP. Vive en producción y no en `AlertService` porque arma su mensaje con los costos de
 * la OP: `AlertService` se carga en cada página (la campana) y no debe arrastrar el resumen de costos.
 */
class ProductionOrderOverconsumptionAlert
{
    public function __construct(
        private readonly AlertService $alertService,
        private readonly ProductionOrderConsumptionCheck $consumptionCheck,
        private readonly ProductionOrderCostSummary $costSummary,
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Al completar una OP, una sola alerta con todas las materias primas que se pasaron de lo planeado más allá de la
     * tolerancia, ajustes de línea incluidos (`ProductionOrderConsumptionCheck`). Lleva el costo del exceso y el margen
     * real por presentación de la OP (`ProductionOrderCostSummary`): el admin decide si revisa la planta, ajusta la
     * fórmula o sube el margen. Nada se reajusta solo.
     *
     * Urgencia alta si alguna pasa el doble de la tolerancia, como la variación de precio.
     */
    public function evaluate(ProductionOrder $order): void
    {
        $rows = $this->consumptionCheck->forOrder($order);

        if ($rows === []) {
            return;
        }

        $tolerance = $rows[0]['tolerance_percentage'];
        $doubled = $this->calculator->mul($tolerance, '2', 4);
        $severity = collect($rows)->contains(fn (array $row): bool => $this->calculator->cmp($row['excess_percentage'], $doubled) >= 0)
            ? AlertSeverity::Alta
            : AlertSeverity::Media;

        $materials = collect($rows)->map(function (array $row): string {
            $detail = sprintf(
                '%s de %s%s',
                NumberFormat::quantity($row['consumed']),
                NumberFormat::quantity($row['planned']),
                $row['unit'] !== null ? " {$row['unit']}" : '',
            );

            if ($row['extra_cost'] !== null) {
                $detail .= ', +'.NumberFormat::cost($row['extra_cost']);
            }

            return sprintf('%s +%s (%s)', $row['code'] ?? "MP #{$row['raw_material_id']}", $this->consumptionCheck->excessLabel($row), $detail);
        })->implode('; ');

        $message = sprintf('%s · %s: %s.', $order->order_number, $order->productDisplayName(), $materials);
        $margin = $this->marginText($order);

        if ($margin !== null) {
            $message .= ' '.$margin.'.';
        }

        $this->alertService->raiseForProductionOrder(AlertType::Sobreconsumo, (int) $order->id, $severity, $message);
    }

    /**
     * «Margen real Galón 31% y Cuñete 30% (lista 40%)», con el resumen de costos de la OP contra la lista de hoy. Sin
     * margen en el producto, lo dice; sin presentaciones con precio, nada.
     */
    private function marginText(ProductionOrder $order): ?string
    {
        $comparison = $this->costSummary->forOrder($order)['comparison'] ?? null;

        if ($comparison === null) {
            return null;
        }

        if ($comparison['list_margin_percentage'] === null) {
            return __('Sin margen en la lista');
        }

        $margins = collect($comparison['presentations'])
            ->filter(fn (array $presentation): bool => $presentation['real_margin_percentage'] !== null)
            ->map(fn (array $presentation): string => trim(($presentation['presentation_label'] ?? '').' '.NumberFormat::percent($presentation['real_margin_percentage'])));

        if ($margins->isEmpty()) {
            return null;
        }

        return __('Margen real :margins (lista :list)', [
            'margins' => $margins->join(', ', ' y '),
            'list' => NumberFormat::percent($comparison['list_margin_percentage']),
        ]);
    }
}
