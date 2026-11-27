import { formatForInput } from '@/lib/formatters';
import { toDecimalPayload } from '@/lib/numeric-input';
import type {
    ProductionOrder,
    ProductionOrderBatchConsumption,
    ProductionOrderBatchUsageRow,
    ProductionOrderDetail,
    ProductionOrderFormData,
    ProductionOrderPackagingFormRow,
    ProductionOrderPackagingPlan,
    ProductionOrderProductLine,
    ProductionOrderProductLineUsageRow,
} from '@/types/production-orders';

/** Una línea de la fórmula de la orden como fila del formulario de consumo. */
export function mapDetailToIngredientFormRow(detail: ProductionOrderDetail) {
    return {
        id: detail.id,
        raw_material_id: detail.raw_material_id,
        step_order: detail.step_order ?? null,
        raw_material_name: detail.raw_material?.code,
        raw_material_unit: detail.raw_material?.unit_symbol,
        planned_quantity: detail.planned_quantity,
        display_quantity: detail.display_quantity ?? null,
        display_unit: detail.display_unit ?? null,
        // Lo real en la unidad en que se digita o, si aún no hay, lo planeado. Llegan como texto con 4 decimales.
        actual_quantity: formatForInput(
            detail.actual_display_quantity ??
                detail.display_quantity ??
                detail.planned_quantity,
        ),
        unit_cost: detail.unit_cost ?? 0,
        total_cost: detail.total_cost ?? 0,
    };
}

/**
 * Fila del formulario a partir del plan guardado. Lo que el operario ya escribió (`edited`) se conserva; lo demás viene
 * del plan. Una sola fuente para el estado inicial de la página y para la sincronización al agregar o quitar filas.
 */
export function mapPackagingPlanToFormRow(
    pack: ProductionOrderPackagingPlan,
    edited?: ProductionOrderPackagingFormRow,
): ProductionOrderPackagingFormRow {
    return {
        id: pack.id,
        presentation: pack.product_variant?.presentation_label ?? 'Unidad',
        presentation_value: pack.product_variant?.presentation_value ?? 1,
        planned_units: pack.planned_units,
        actual_units: edited
            ? edited.actual_units
            : formatForInput(pack.actual_units ?? pack.planned_units),
        package_code: pack.package_code ?? null,
        new_containers_used: edited
            ? edited.new_containers_used
            : formatForInput(pack.new_containers_used),
        label_raw_material_id: edited
            ? edited.label_raw_material_id
            : (pack.label_raw_material_id ?? null),
        saved_label_raw_material_id: pack.label_raw_material_id ?? null,
        labels_used: edited
            ? edited.labels_used
            : formatForInput(pack.labels_used),
    };
}

/**
 * Lo que se escribe de un lote a partir de lo guardado (al enviar a revisión): quien aprueba lo ve y lo corrige. Lo que
 * el operario ya escribió (`edited`) se conserva. Una sola fuente para el estado inicial de la página y para la
 * sincronización al agregar o quitar lotes.
 */
export function mapBatchSourceToUsageRow(
    source: ProductionOrderBatchConsumption,
    edited?: ProductionOrderBatchUsageRow,
): ProductionOrderBatchUsageRow {
    if (edited) {
        return edited;
    }

    return {
        id: source.id,
        has_leftover: source.leftover_kg !== null,
        leftover_kg: formatForInput(source.leftover_kg),
    };
}

/** Lo gastado de un producto a partir de lo guardado (al enviar a revisión); lo que ya se escribió se conserva. */
export function mapProductLineToUsageRow(
    line: ProductionOrderProductLine,
    edited?: ProductionOrderProductLineUsageRow,
): ProductionOrderProductLineUsageRow {
    return edited ?? { id: line.id, actual_kg: formatForInput(line.actual_kg) };
}

/** Lo que el formulario de la OP trae al abrir: lo guardado en la orden. */
export function initialProductionOrderFormData(
    order: ProductionOrder,
): ProductionOrderFormData {
    return {
        viscosity_ku: order.viscosity_ku ?? '',
        grinding_hg: order.grinding_hg ?? '',
        quality_solids: order.quality_solids ?? '',
        agitation_start_time: order.agitation_start_time ?? '',
        agitation_end_time: order.agitation_end_time ?? '',
        packaging_start_time: order.packaging_start_time ?? '',
        packaging_end_time: order.packaging_end_time ?? '',
        responsible_name: order.responsible_name ?? '',
        quality_responsible_user_id: order.quality_responsible_user_id ?? null,
        spillage_quantity: order.spillage_quantity ?? 0,
        density_kg_per_gallon: order.density_kg_per_gallon ?? '',
        // Lo que la planta informó al enviar a revisión: quien aprueba lo ve y lo confirma.
        remnant_quantity_kg: formatForInput(order.remnant_quantity_kg),
        remnant_notes: order.remnant_notes ?? '',
        notes: order.notes ?? '',
        ingredients: (order.details ?? []).map(mapDetailToIngredientFormRow),
        packaging: (order.packaging_plans ?? []).map((pack) =>
            mapPackagingPlanToFormRow(pack),
        ),
        // Los motivos guardados (si la orden volvió de revisión, se editan); el servidor dice cuáles faltan.
        overconsumption_reasons: Object.fromEntries(
            (order.consumption_reasons ?? []).map((reason) => [
                String(reason.raw_material_id),
                reason.reason,
            ]),
        ),
        product_overconsumption_reasons: Object.fromEntries(
            (order.product_consumption_reasons ?? []).map((reason) => [
                String(reason.product_id),
                reason.reason,
            ]),
        ),
        // Por lote de PT usado: lo que la planta registró al enviar a revisión (quien aprueba lo ve y lo corrige).
        batch_consumptions: (order.batch_consumptions ?? []).map((source) =>
            mapBatchSourceToUsageRow(source),
        ),
        // Por producto usado: lo gastado que la planta registró al enviar a revisión.
        product_lines: (order.product_lines ?? []).map((line) =>
            mapProductLineToUsageRow(line),
        ),
    };
}

/** Lo digitado como lo espera el servidor: decimales con punto y vacíos como null. */
export function normalizeProductionOrderFormData(
    data: ProductionOrderFormData,
) {
    return {
        ...data,
        viscosity_ku: toDecimalPayload(data.viscosity_ku),
        grinding_hg: toDecimalPayload(data.grinding_hg),
        quality_solids: toDecimalPayload(data.quality_solids),
        spillage_quantity: toDecimalPayload(data.spillage_quantity),
        density_kg_per_gallon: toDecimalPayload(data.density_kg_per_gallon),
        remnant_quantity_kg: toDecimalPayload(data.remnant_quantity_kg),
        ingredients: data.ingredients.map((ingredient) => ({
            id: ingredient.id,
            actual_quantity: toDecimalPayload(ingredient.actual_quantity),
        })),
        packaging: data.packaging.map((pack) => ({
            id: pack.id,
            actual_units: pack.actual_units,
            // Vacío = tantos como unidades envasadas (lo resuelve el servidor).
            new_containers_used:
                pack.new_containers_used === ''
                    ? null
                    : pack.new_containers_used,
            label_raw_material_id: pack.label_raw_material_id,
            labels_used: pack.labels_used === '' ? null : pack.labels_used,
        })),
        product_lines: data.product_lines.map((row) => ({
            id: row.id,
            actual_kg: toDecimalPayload(row.actual_kg),
        })),
        // Lo que quedó solo viaja con «sí»; con «no», nada (el servidor lo toma como que no quedó producto).
        batch_consumptions: data.batch_consumptions.map((row) => ({
            id: row.id,
            has_leftover: row.has_leftover,
            leftover_kg: row.has_leftover
                ? toDecimalPayload(row.leftover_kg)
                : null,
        })),
    };
}
