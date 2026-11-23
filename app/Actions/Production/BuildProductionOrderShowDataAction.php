<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderLineAdjustment;
use App\Models\ProductionOrderPackagingPlan;
use App\Models\ProductionOrderProductLine;
use App\Models\RemnantConsumption;
use App\Services\AvailableBatchesService;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;
use App\Services\Production\BatchSourceDrawdown;
use App\Services\Production\ProductionOrderConsumptionCheck;
use App\Services\Production\ProductionOrderCostSummary;
use App\Services\Production\ProductionOrderLineUnits;
use App\Services\Production\ProductLineCosts;
use App\Services\Production\RemnantDrawdown;

class BuildProductionOrderShowDataAction
{
    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly ProductionOrderLineUnits $lineUnits,
        private readonly RemnantDrawdown $remnantDrawdown,
        private readonly ProductionOrderCostSummary $costSummary,
        private readonly ProductionOrderConsumptionCheck $consumptionCheck,
        private readonly AvailableBatchesService $availableBatches,
        private readonly BatchSourceDrawdown $batchSources,
        private readonly ProductLineCosts $productLineCosts,
    ) {}

    /**
     * Carga relaciones y transforma la orden en un array para la pantalla Inertia.
     *
     * @param  bool  $canPlanRemnant  si quien la ve puede agregar y quitar fuentes (saldos y lotes): trae lo libre de la bodega
     * @return array<string, mixed>
     */
    public function execute(ProductionOrder $productionOrder, bool $includeCosts = true, bool $canPlanRemnant = false): array
    {
        $productionOrder->load([
            'product',
            'qrCode',
            'remnant',
            // En el orden en que se agregaron: sin `orderBy`, PostgreSQL las devuelve en el orden en que las encuentra.
            'remnantConsumptions' => fn ($query) => $query->orderBy('id'),
            'remnantConsumptions.remnant.sourceOrder:id,order_number,lot_number,color',
            'remnantConsumptions.remnant.product:id,name',
            'remnantConsumptions.consumedBy',
            // «Productos a usar»: las líneas (las de la fórmula por paso, después las agregadas) y sus lotes.
            'productLines.product:id,name',
            'batchConsumptions' => fn ($query) => $query->orderBy('id'),
            'batchConsumptions.batch.product:id,name',
            'batchConsumptions.batch.productVariant',
            'batchConsumptions.batch.productionOrder',
            'formula.details.rawMaterial',
            'formula.details.unitOfMeasure',
            'details.rawMaterial.unitOfMeasure',
            'packagingPlans.productVariant.packageRawMaterial',
            'packagingPlans.packageRawMaterial',
            'packagingPlans.labelRawMaterial',
            'finishedInventoryMovements',
            'warehouse',
            'lineAdjustments.rawMaterial',
            'consumptionReasons.rawMaterial:id,code',
            'consumptionReasons.product:id,name',
            'submittedBy:id,name',
            'reviewedBy:id,name',
            'qualityResponsibleUser:id,name,job_title',
        ]);

        $formulaDetailsByKey = collect();
        if ($productionOrder->formula) {
            $formulaDetailsByKey = $productionOrder->formula->details
                ->mapWithKeys(fn ($fd) => [$fd->step_order.'-'.$fd->raw_material_id => $fd]);
        }

        $lineFactors = $this->lineUnits->conversionFactors($productionOrder);
        $orderIsOpen = in_array($productionOrder->status, ProductionOrderStatus::open(), true);

        // Una sola cuenta para la tarjeta, la vista previa, el PDF y el Excel (null si la orden está cancelada).
        $costSummary = $includeCosts ? $this->costSummary->forOrder($productionOrder) : null;

        $sourceState = fn (bool $isApplied): string => match (true) {
            $isApplied => 'applied',
            $orderIsOpen => 'planned',
            default => 'unused',
        };
        $batchSources = $productionOrder->batchConsumptions
            ->map(fn (ProductionOrderBatchConsumption $source): array => $this->batchSourceRow($source, $sourceState($source->isApplied()), $includeCosts, $canPlanRemnant))
            ->values();
        // Lo que cubre y cuesta cada producto: sin costo en una orden cancelada (sus fuentes no se usaron).
        $lineCosts = $this->productLineCosts->forOrder($productionOrder, $includeCosts && $productionOrder->status !== ProductionOrderStatus::Cancelled);

        // La página pública vive en el dominio del QR: va absoluta. La imagen sale del ERP, con la sesión de quien ve la OP.
        $qrCode = $productionOrder->qrCode;
        $hasActiveQr = $qrCode !== null && $qrCode->is_active;
        $qrLandingUrl = $hasActiveQr ? $qrCode->landingUrl() : null;
        $qrImageUrl = $hasActiveQr
            ? route('production-orders.qr-image', ['production_order' => $productionOrder], false)
            : null;

        return [
            'id' => $productionOrder->id,
            'order_number' => $productionOrder->order_number,
            'lot_number' => $productionOrder->lot_number,
            // Color que pidió el cliente y el nombre del producto con él (3.4): pantalla, PDF y Excel usan el mismo.
            'color' => $productionOrder->color,
            'product_display_name' => $productionOrder->productDisplayName(),
            'status' => $productionOrder->status->value,
            'quantity' => (float) $productionOrder->quantity,
            'actual_quantity' => $productionOrder->actual_quantity !== null ? (float) $productionOrder->actual_quantity : null,
            'yield_real_quantity' => $productionOrder->yield_real_quantity !== null ? (float) $productionOrder->yield_real_quantity : null,
            'yield_theoretical_quantity' => $productionOrder->yield_theoretical_quantity !== null ? (float) $productionOrder->yield_theoretical_quantity : null,
            'yield_variance_quantity' => $productionOrder->yield_variance_quantity !== null ? (float) $productionOrder->yield_variance_quantity : null,
            'yield_percentage' => $productionOrder->yield_percentage !== null ? (float) $productionOrder->yield_percentage : null,
            'planned_date' => optional($productionOrder->planned_date)->toDateString(),
            'completion_date' => optional($productionOrder->completion_date)->toDateString(),
            'viscosity_ku' => $productionOrder->viscosity_ku !== null ? (float) $productionOrder->viscosity_ku : null,
            'grinding_hg' => $productionOrder->grinding_hg !== null ? (float) $productionOrder->grinding_hg : null,
            'quality_solids' => $productionOrder->quality_solids !== null ? (float) $productionOrder->quality_solids : null,
            'agitation_start_time' => optional($productionOrder->agitation_start_time)->toISOString(),
            'agitation_end_time' => optional($productionOrder->agitation_end_time)->toISOString(),
            'packaging_start_time' => optional($productionOrder->packaging_start_time)->toISOString(),
            'packaging_end_time' => optional($productionOrder->packaging_end_time)->toISOString(),
            'responsible_name' => $productionOrder->responsible_name,
            'spillage_quantity' => (float) $productionOrder->spillage_quantity,
            // Texto, no float: el formulario lo vuelve a enviar y un float puede perder decimales.
            'remnant_quantity_gallons' => $productionOrder->remnant_quantity_gallons,
            'remnant_quantity_kg' => $productionOrder->remnant_quantity_kg,
            'remnant_notes' => $productionOrder->remnant_notes,
            'density_kg_per_gallon' => $productionOrder->density_kg_per_gallon !== null ? (float) $productionOrder->density_kg_per_gallon : null,
            'notes' => $productionOrder->notes,
            'submitted_at' => $productionOrder->submitted_at?->toISOString(),
            'reviewed_at' => $productionOrder->reviewed_at?->toISOString(),
            'rejection_reason' => $productionOrder->rejection_reason,
            'submitted_by' => $productionOrder->submittedBy ? [
                'id' => $productionOrder->submittedBy->id,
                'name' => $productionOrder->submittedBy->name,
            ] : null,
            'reviewed_by' => $productionOrder->reviewedBy ? [
                'id' => $productionOrder->reviewedBy->id,
                'name' => $productionOrder->reviewedBy->name,
            ] : null,
            'quality_responsible_user_id' => $productionOrder->quality_responsible_user_id,
            'quality_responsible_user' => $productionOrder->qualityResponsibleUser ? [
                'id' => $productionOrder->qualityResponsibleUser->id,
                'name' => $productionOrder->qualityResponsibleUser->name,
                'job_title' => $productionOrder->qualityResponsibleUser->job_title,
            ] : null,
            'qr_landing_url' => $qrLandingUrl,
            'qr_image_url' => $qrImageUrl,
            'product' => $productionOrder->product ? [
                'id' => $productionOrder->product->id,
                'name' => $productionOrder->product->name,
                'quality_viscosity_lower' => $productionOrder->product->quality_viscosity_lower !== null
                    ? (float) $productionOrder->product->quality_viscosity_lower
                    : null,
                'quality_viscosity_upper' => $productionOrder->product->quality_viscosity_upper !== null
                    ? (float) $productionOrder->product->quality_viscosity_upper
                    : null,
                'quality_fineness_lower' => $productionOrder->product->quality_fineness_lower !== null
                    ? (float) $productionOrder->product->quality_fineness_lower
                    : null,
                'quality_fineness_upper' => $productionOrder->product->quality_fineness_upper !== null
                    ? (float) $productionOrder->product->quality_fineness_upper
                    : null,
                'quality_solids_lower' => $productionOrder->product->quality_solids_lower !== null
                    ? (float) $productionOrder->product->quality_solids_lower
                    : null,
                'quality_solids_upper' => $productionOrder->product->quality_solids_upper !== null
                    ? (float) $productionOrder->product->quality_solids_upper
                    : null,
            ] : null,
            'formula' => $productionOrder->formula ? [
                'id' => $productionOrder->formula->id,
                'version' => $productionOrder->formula->version,
                'name' => $productionOrder->formula->name,
                'notes' => $productionOrder->formula->notes,
            ] : null,
            'warehouse' => $productionOrder->warehouse ? [
                'id' => $productionOrder->warehouse->id,
                'name' => $productionOrder->warehouse->name,
            ] : null,
            ...($includeCosts ? [
                'cost_summary' => $costSummary,
            ] : []),
            // Las materias primas que se pasaron de lo planeado (en revisión y completada), con su motivo. La cuenta es
            // del servidor; el costo del exceso, solo con «Ver costos».
            'consumption_tolerance_percentage' => $productionOrder->consumption_tolerance_percentage,
            'overconsumption' => array_map(fn (array $row): array => [
                'raw_material_id' => $row['raw_material_id'],
                'code' => $row['code'],
                'unit' => $row['unit'],
                'planned' => $row['planned'],
                'consumed' => $row['consumed'],
                'excess_percentage' => $row['excess_percentage'],
                'tolerance_percentage' => $row['tolerance_percentage'],
                'needs_reason' => $row['needs_reason'],
                'reason' => $row['reason'],
                ...($includeCosts ? ['extra_cost' => $row['extra_cost']] : []),
            ], $this->consumptionCheck->forOrder($productionOrder)),
            // Los productos que se pasaron de lo planeado, igual que las materias primas (en kilos).
            'product_overconsumption' => array_map(fn (array $row): array => [
                'product_id' => $row['product_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'planned' => $row['planned'],
                'consumed' => $row['consumed'],
                'excess_percentage' => $row['excess_percentage'],
                'tolerance_percentage' => $row['tolerance_percentage'],
                'needs_reason' => $row['needs_reason'],
                'reason' => $row['reason'],
                ...($includeCosts ? ['extra_cost' => $row['extra_cost']] : []),
            ], $this->consumptionCheck->productsForOrder($productionOrder)),
            // Los motivos guardados, para volver a editarlos si la orden vuelve de revisión.
            'consumption_reasons' => $productionOrder->consumptionReasons
                ->whereNotNull('raw_material_id')
                ->sortBy('raw_material_id')
                ->map(fn ($reason): array => [
                    'raw_material_id' => (int) $reason->raw_material_id,
                    'code' => $reason->rawMaterial?->code,
                    'reason' => $reason->reason,
                ])
                ->values(),
            'product_consumption_reasons' => $productionOrder->consumptionReasons
                ->whereNotNull('product_id')
                ->sortBy('product_id')
                ->map(fn ($reason): array => [
                    'product_id' => (int) $reason->product_id,
                    'name' => $reason->product?->name,
                    'reason' => $reason->reason,
                ])
                ->values(),
            'details' => $productionOrder->details->map(function (ProductionOrderDetail $detail) use ($formulaDetailsByKey, $lineFactors, $productionOrder, $includeCosts) {
                $formulaDetail = $formulaDetailsByKey->get($detail->step_order.'-'.$detail->raw_material_id);

                $displayQuantity = null;
                $displayUnit = null;
                $factor = $lineFactors[(int) $detail->id];
                // Con factor, la línea se digita en la unidad de la fórmula. Sin él, en la de la materia prima.
                if ($factor !== null) {
                    $displayQuantity = $this->calculator->mul(
                        (string) $formulaDetail->quantity,
                        (string) $productionOrder->quantity,
                        4
                    );
                    $displayUnit = $formulaDetail->unitOfMeasure->symbol;
                }

                $row = [
                    'id' => $detail->id,
                    'raw_material_id' => (int) $detail->raw_material_id,
                    'step_order' => (int) $detail->step_order,
                    // Como texto, sin pasar por float. `actual_display_quantity` va en la unidad en que se digita.
                    'planned_quantity' => (string) $detail->planned_quantity,
                    'display_quantity' => $displayQuantity,
                    'display_unit' => $displayUnit,
                    'actual_display_quantity' => $detail->actual_quantity !== null
                        ? $this->lineUnits->toDisplayUnit((string) $detail->actual_quantity, $factor)
                        : null,
                    'raw_material' => $detail->rawMaterial ? [
                        'id' => $detail->rawMaterial->id,
                        'code' => $detail->rawMaterial->code,
                        'unit_symbol' => $detail->rawMaterial->unitOfMeasure?->symbol,
                    ] : null,
                ];

                if ($includeCosts) {
                    $row['unit_cost'] = (string) $detail->unit_cost;
                    $row['total_cost'] = (string) $detail->total_cost;
                }

                return $row;
            })->values(),
            'packaging_plans' => $productionOrder->packagingPlans->map(function (ProductionOrderPackagingPlan $plan) use ($productionOrder) {
                $presentationValue = (float) ($plan->productVariant?->presentation_value ?? 1);
                // Completada, vale solo el envase guardado al completar (vacío si la presentación no tenía): el documento
                // cerrado no cambia si después se cambia el de la presentación. Antes de completar, el de la presentación.
                $packageCode = $productionOrder->status === ProductionOrderStatus::Completed
                    ? $plan->packageRawMaterial?->code
                    : $plan->productVariant?->packageRawMaterial?->code;

                $row = [
                    'id' => $plan->id,
                    'planned_units' => (float) $plan->planned_units,
                    'actual_units' => $plan->actual_units !== null ? (float) $plan->actual_units : null,
                    // Empaque (3.7). Vacío = tantos como unidades envasadas. El envase es el consumido (guardado al
                    // completar) o, con la OP abierta, el de la presentación; la etiqueta es la del plan.
                    'package_code' => $packageCode,
                    'new_containers_used' => $plan->new_containers_used,
                    'label_raw_material_id' => $plan->label_raw_material_id,
                    'label_code' => $plan->labelRawMaterial?->code,
                    'labels_used' => $plan->labels_used,
                    'product_variant' => $plan->productVariant ? [
                        'id' => $plan->productVariant->id,
                        'presentation_label' => $plan->productVariant->presentation_label,
                        'presentation_value' => $presentationValue,
                    ] : null,
                ];

                // El costo de cada presentación va en `cost_summary.packaging`, con la misma cuenta que la tarjeta.
                return $row;
            })->values(),
            'line_adjustments' => $productionOrder->lineAdjustments->map(fn (ProductionOrderLineAdjustment $adj) => [
                'id' => $adj->id,
                'raw_material_id' => (int) $adj->raw_material_id,
                'quantity' => (float) $adj->quantity,
                'reason' => $adj->reason,
                'notes' => $adj->notes,
                'created_at' => $adj->created_at?->toISOString(),
                'raw_material' => $adj->rawMaterial ? [
                    'id' => $adj->rawMaterial->id,
                    'code' => $adj->rawMaterial->code,
                ] : null,
            ])->values(),
            'remnant' => $productionOrder->remnant ? [
                'id' => $productionOrder->remnant->id,
                'original_quantity_gallons' => (float) $productionOrder->remnant->original_quantity_gallons,
                'available_quantity_gallons' => (float) $productionOrder->remnant->available_quantity_gallons,
                'original_quantity_kg' => (float) $productionOrder->remnant->original_quantity_kg,
                'available_quantity_kg' => (float) $productionOrder->remnant->available_quantity_kg,
                'density_kg_per_gallon' => (float) $productionOrder->remnant->density_kg_per_gallon,
                ...($includeCosts ? [
                    'cost_per_gallon' => $productionOrder->remnant->cost_per_gallon !== null ? (float) $productionOrder->remnant->cost_per_gallon : null,
                ] : []),
                'status' => $productionOrder->remnant->status->value,
                'status_label' => $productionOrder->remnant->status->label(),
            ] : null,
            // Saldos de PT a usar: líneas planeadas hasta que la orden se completa. Los galones y el costo de una línea
            // planeada son un estimado con la regla con que se aplicará; los reales se guardan al completar.
            'remnant_consumptions' => $productionOrder->remnantConsumptions->map(function (RemnantConsumption $consumption) use ($includeCosts, $canPlanRemnant, $orderIsOpen) {
                $isApplied = $consumption->isApplied();
                // Sin aplicar en una orden que ya no está abierta (cancelada): se conserva como historial, pero no se usó.
                $state = match (true) {
                    $isApplied => 'applied',
                    $orderIsOpen => 'planned',
                    default => 'unused',
                };
                // El real si ya se aplicó; el estimado si está planeada; ninguno si no se usó.
                $cost = $includeCosts && $state !== 'unused' ? $this->remnantDrawdown->lineCost($consumption) : null;

                return [
                    'id' => $consumption->id,
                    'production_order_product_line_id' => $consumption->production_order_product_line_id,
                    'remnant_id' => $consumption->remnant_id,
                    'source_order_number' => $consumption->remnant?->sourceOrder?->order_number,
                    'source_lot_number' => $consumption->remnant?->sourceOrder?->lot_number,
                    // Qué se mezcla en esta orden: el producto con el color de la orden de origen.
                    'source_product_name' => ProductionOrder::nameWithColor($consumption->remnant->product->name, $consumption->remnant->sourceOrder->color),
                    'quantity_gallons' => (float) $consumption->quantity_gallons,
                    'quantity_kg' => (float) $consumption->quantity_kg,
                    ...($includeCosts ? [
                        'consumed_cost' => $cost !== null ? (float) $cost : null,
                    ] : []),
                    // applied: descontado del saldo · planned: planeado en una orden abierta · unused: la orden ya no está abierta.
                    'state' => $state,
                    'notes' => $consumption->notes,
                    'consumed_at' => $consumption->consumed_at->toISOString(),
                    'consumed_by' => $consumption->consumedBy ? [
                        'id' => $consumption->consumedBy->id,
                        'name' => $consumption->consumedBy->name,
                    ] : null,
                    'can' => [
                        'remove' => $canPlanRemnant && ! $isApplied,
                    ],
                ];
            })->values(),
            // Los lotes de PT que usa la orden, en unidades enteras; al enviar o completar se escribe lo que quedó, pesado,
            // en un envase abierto.
            'batch_consumptions' => $batchSources,
            // Las líneas de «Productos a usar»: cuánto planea la fórmula y cuánto cubren sus fuentes.
            'product_lines' => $productionOrder->productLines
                ->map(fn (ProductionOrderProductLine $line): array => $this->productLineRow($line, $lineCosts[(int) $line->id], $includeCosts))
                ->values(),
            'available_remnants' => $canPlanRemnant
                ? $this->availableRemnants->forOrder($productionOrder)
                : [],
            'available_batches' => $canPlanRemnant
                ? $this->availableBatches->forOrder($productionOrder, $includeCosts)
                : [],
        ];
    }

    /**
     * Un lote usado por la orden. Los kilos por unidad son los fijados al completar o, antes, los del lote
     * (`FinishedProductBatch::kgPerUnit`, la única cuenta); los kilos, unidades × kilos por unidad (null sin peso por
     * galón). El costo es el real si ya se aplicó y el estimado si no; los dos, solo con «Ver costos».
     *
     * @return array<string, mixed>
     */
    private function batchSourceRow(ProductionOrderBatchConsumption $source, string $state, bool $includeCosts, bool $canPlanRemnant): array
    {
        $batch = $source->batch;
        $sourceOrder = $batch->productionOrder;
        $isApplied = $source->isApplied();
        $kgPerUnit = $isApplied && $source->kg_per_unit !== null ? (string) $source->kg_per_unit : $batch->kgPerUnit();

        return [
            'id' => $source->id,
            'production_order_product_line_id' => $source->production_order_product_line_id,
            'finished_product_batch_id' => $source->finished_product_batch_id,
            'lot_number' => $sourceOrder?->lot_number,
            'source_order_number' => $sourceOrder?->order_number,
            'source_product_name' => ProductionOrder::nameWithColor((string) $batch->product?->name, $sourceOrder?->color),
            'presentation_label' => $batch->productVariant?->presentation_label,
            'units' => (int) $source->units,
            'kg_per_unit' => $kgPerUnit,
            'kg' => $kgPerUnit !== null ? $this->calculator->mul((string) $source->units, $kgPerUnit, 4) : null,
            // Lo registrado al enviar a revisión (o al completar): quien aprueba lo ve y lo corrige.
            'leftover_kg' => $source->leftover_kg !== null ? (string) $source->leftover_kg : null,
            ...($includeCosts ? [
                'transfer_unit_cost' => $batch->transfer_unit_cost !== null ? (string) $batch->transfer_unit_cost : null,
                'consumed_cost' => $state === 'unused' ? null : $this->batchSources->lineCost($source),
            ] : []),
            'state' => $state,
            'can' => [
                'update' => $canPlanRemnant && ! $isApplied,
                'remove' => $canPlanRemnant && ! $isApplied,
            ],
        ];
    }

    /**
     * Una línea de producto: el renglón del producto en la tabla de consumo. Lo planeado (null en una agregada), lo real
     * gastado (lo escrito al enviar a revisión o completar), lo que cubren sus fuentes y lo que falta, y su costo
     * (`ProductLineCosts`, solo con «Ver costos»).
     *
     * @param  array{covered_kg: string, has_unknown_kg: bool, cost: string|null, unit_cost: string|null}  $costs
     * @return array<string, mixed>
     */
    private function productLineRow(ProductionOrderProductLine $line, array $costs, bool $includeCosts): array
    {
        $covered = $costs['covered_kg'];
        $planned = $line->planned_kg !== null ? (string) $line->planned_kg : null;
        // Con un lote sin peso por galón no se sabe cuánto cubre: tampoco cuánto falta (el buscador no propone).
        $missing = $planned !== null && ! $costs['has_unknown_kg'] ? $this->calculator->sub($planned, $covered, 4) : null;

        return [
            'id' => $line->id,
            'product_id' => (int) $line->product_id,
            'product_name' => $line->product?->name,
            'step_order' => $line->step_order,
            'origin' => $line->origin->value,
            'planned_kg' => $planned,
            'actual_kg' => $line->actual_kg !== null ? (string) $line->actual_kg : null,
            'covered_kg' => $covered,
            'missing_kg' => $missing !== null && $this->calculator->isPositive($missing) ? $missing : ($missing !== null ? '0.0000' : null),
            'has_unknown_kg' => $costs['has_unknown_kg'],
            ...($includeCosts ? [
                'cost' => $costs['cost'],
                'unit_cost' => $costs['unit_cost'],
            ] : []),
        ];
    }
}
