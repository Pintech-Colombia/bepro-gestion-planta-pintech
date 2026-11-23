<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderProductLine;
use App\Models\RemnantConsumption;
use App\Services\DecimalCalculator;
use App\Support\NumberFormat;

class BuildProductionOrderPdfMaterialsAction
{
    public function __construct(
        private readonly DecimalCalculator $calculator
    ) {}

    /**
     * Filas para PDF/Excel: pasos ordenados por step_order (órdenes no completadas)
     * o consolidado por materia prima (orden completada).
     *
     * El producto terminado que la orden lleva como ingrediente va en la misma tabla, como en la hoja de planta: en su
     * paso (los agregados en la orden, al final), con sus kilos planeados, lo gastado y de dónde sale (`kind` =
     * `product`, `observations`: un renglón por línea de la celda). Una fila de MP lleva `kind` = `material`.
     *
     * @return array{mode: string, rows: list<array<string, mixed>>, totals: array{planned_quantity: string, kg: string, grams: string, actual_quantity: string}}
     */
    public function execute(ProductionOrder $order): array
    {
        $order->loadMissing([
            'details.rawMaterial',
            'productLines.product',
            'remnantConsumptions.remnant.sourceOrder',
            'batchConsumptions.batch.productionOrder',
            'batchConsumptions.batch.productVariant',
        ]);
        $productRows = $this->productRows($order);

        $details = $order->details->sortBy('step_order')->values();

        if ($order->status === ProductionOrderStatus::Completed) {
            $rows = $details->groupBy('raw_material_id')
                ->map(function ($group) {
                    /** @var ProductionOrderDetail $first */
                    $first = $group->first();

                    $plannedArray = $group->map(fn (ProductionOrderDetail $d) => (string) $d->planned_quantity)->all();
                    $actualArray = $group->map(fn (ProductionOrderDetail $d) => (string) ($d->actual_quantity ?? '0'))->all();

                    return [
                        'kind' => 'material',
                        'raw_material_code' => $first->rawMaterial->code ?? 'N/A',
                        'raw_material_name' => $first->rawMaterial->code ?? 'N/A',
                        'planned_quantity' => $this->calculator->sum($plannedArray, 4),
                        'actual_quantity' => $this->calculator->sum($actualArray, 4),
                    ];
                })
                ->values()
                ->sortBy('raw_material_code')
                ->map(fn (array $row): array => $this->withDisplayQuantities($row))
                ->values()
                ->all();
            $rows = [...$rows, ...$productRows];

            return [
                'mode' => 'consolidated',
                'rows' => $rows,
                'totals' => $this->buildTotals($rows),
            ];
        }

        $rows = [];

        foreach ($details as $detail) {
            $rows[] = $this->withDisplayQuantities([
                'kind' => 'material',
                'step_order' => (int) $detail->step_order,
                'raw_material_code' => $detail->rawMaterial->code ?? 'N/A',
                'raw_material_name' => $detail->rawMaterial->code ?? 'N/A',
                'planned_quantity' => (string) $detail->planned_quantity,
                'actual_quantity' => $detail->actual_quantity !== null ? (string) $detail->actual_quantity : null,
            ]);
        }

        // Por paso; dentro del mismo paso, la MP primero. Sin paso (un producto agregado en la orden), al final.
        $rows = collect([...$rows, ...$productRows])
            ->map(fn (array $row, int $position): array => [$row, $position])
            ->sort(fn (array $a, array $b): int => [$a[0]['step_order'] ?? PHP_INT_MAX, $a[1]] <=> [$b[0]['step_order'] ?? PHP_INT_MAX, $b[1]])
            ->map(fn (array $pair): array => $pair[0])
            ->values()
            ->all();

        return [
            'mode' => 'steps',
            'rows' => $rows,
            'totals' => $this->buildTotals($rows),
        ];
    }

    /**
     * Una fila por línea de producto: el nombre (va en la columna del código), lo planeado en kg, lo gastado y lo que va
     * en OBSERVACIONES, igual en el PDF y el Excel:
     * - sus fuentes («Saldo · Lote 1620 (OP-2026-0001) · 10 kg», «Lote 1682 (OP-2026-0001) · 20 × Cuñete 4G»), o una
     *   raya para que la planta anote de dónde sale si nadie lo eligió;
     * - mientras se produce y con un lote elegido, la raya de lo que quedó en un cuñete abierto (desde la revisión ya
     *   está registrado);
     * - lo que quedó, una vez registrado.
     *
     * @return list<array<string, mixed>>
     */
    private function productRows(ProductionOrder $order): array
    {
        $isProducing = in_array($order->status, [ProductionOrderStatus::Pending, ProductionOrderStatus::InProgress], true);

        return $order->productLines
            ->map(function (ProductionOrderProductLine $line) use ($order, $isProducing): array {
                $remnants = $order->remnantConsumptions
                    ->filter(fn (RemnantConsumption $consumption): bool => (int) $consumption->production_order_product_line_id === (int) $line->id);
                $lots = $order->batchConsumptions
                    ->filter(fn (ProductionOrderBatchConsumption $source): bool => (int) $source->production_order_product_line_id === (int) $line->id);

                $sources = [
                    // Con la nota del saldo («Tanque 3»): le dice a la planta dónde está.
                    ...$remnants->map(fn (RemnantConsumption $consumption): string => sprintf(
                        'Saldo · Lote %s (%s) · %s kg%s',
                        $consumption->remnant?->sourceOrder?->lot_number,
                        $consumption->remnant?->sourceOrder?->order_number,
                        NumberFormat::quantity((string) $consumption->quantity_kg),
                        filled($consumption->notes) ? ' · '.$consumption->notes : '',
                    ))->all(),
                    ...$lots->map(fn (ProductionOrderBatchConsumption $source): string => sprintf(
                        'Lote %s (%s) · %s × %s',
                        $source->batch?->productionOrder?->lot_number,
                        $source->batch?->productionOrder?->order_number,
                        NumberFormat::units((string) $source->units),
                        $source->batch?->productVariant?->presentation_label ?? 'unidad',
                    ))->all(),
                ];
                $leftovers = $lots
                    ->filter(fn (ProductionOrderBatchConsumption $source): bool => $source->leftover_kg !== null)
                    ->map(fn (ProductionOrderBatchConsumption $source): string => sprintf(
                        'Quedaron %s kg en un cuñete abierto del lote %s',
                        NumberFormat::quantity((string) $source->leftover_kg),
                        $source->batch?->productionOrder?->lot_number,
                    ))
                    ->values()
                    ->all();

                return $this->withDisplayQuantities([
                    'kind' => 'product',
                    'step_order' => $line->step_order,
                    'raw_material_code' => (string) $line->product?->name,
                    'raw_material_name' => (string) $line->product?->name,
                    'planned_quantity' => $line->planned_kg !== null ? (string) $line->planned_kg : '0',
                    'actual_quantity' => $line->actual_kg !== null ? (string) $line->actual_kg : null,
                    'observations' => [
                        ...($sources !== [] ? $sources : ['De dónde sale: ______________________']),
                        ...($isProducing && $lots->isNotEmpty() ? ['Quedó en cuñete abierto: ________ kg'] : []),
                        ...$leftovers,
                    ],
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withDisplayQuantities(array $row): array
    {
        $plannedQuantity = (string) ($row['planned_quantity'] ?? '0');

        $row['display_kg'] = $this->calculator->cmp($plannedQuantity, '1', 4) >= 0
            ? $plannedQuantity
            : '0';
        $row['display_grams'] = $this->calculator->cmp($plannedQuantity, '1', 4) < 0
            ? $this->calculator->mul($plannedQuantity, '1000', 4)
            : '0';

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{planned_quantity: string, kg: string, grams: string, actual_quantity: string}
     */
    private function buildTotals(array $rows): array
    {
        $plannedQuantities = [];
        $kilograms = [];
        $grams = [];
        $actualQuantities = [];

        foreach ($rows as $row) {
            $plannedQuantities[] = (string) ($row['planned_quantity'] ?? '0');
            $kilograms[] = (string) ($row['display_kg'] ?? '0');
            $grams[] = (string) ($row['display_grams'] ?? '0');
            $actualQuantities[] = (string) ($row['actual_quantity'] ?? '0');
        }

        return [
            'planned_quantity' => $this->calculator->sum($plannedQuantities, 4),
            'kg' => $this->calculator->sum($kilograms, 4),
            'grams' => $this->calculator->sum($grams, 4),
            'actual_quantity' => $this->calculator->sum($actualQuantities, 4),
        ];
    }
}
