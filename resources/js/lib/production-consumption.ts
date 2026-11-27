import type { OverconsumptionReasonRow } from '@/components/production/overconsumption-reasons';
import type {
    ProductionOrderIngredientFormRow,
    ProductionOrderOverconsumption,
    ProductionOrderProductLine,
    ProductionOrderProductOverconsumption,
} from '@/types/production-orders';

/**
 * El orden de la tabla de consumo de la OP, el mismo en el formulario y en la ficha de lectura: por paso, como se
 * agregan en planta; dentro del mismo paso, en el orden recibido (la materia prima primero). Sin paso (un producto
 * agregado en la orden), al final.
 */
export function orderByStep<T>(
    rows: T[],
    stepOf: (row: T) => number | null | undefined,
): T[] {
    return rows
        .map((row, position) => ({ row, position }))
        .sort(
            (a, b) =>
                (stepOf(a.row) ?? Number.POSITIVE_INFINITY) -
                    (stepOf(b.row) ?? Number.POSITIVE_INFINITY) ||
                a.position - b.position,
        )
        .map(({ row }) => row);
}

/** El código de cada materia prima de la orden por id, para los motivos de sobreconsumo. */
export function materialCodesOf(
    rows: ProductionOrderIngredientFormRow[],
): Map<number, string> {
    const codes = new Map<number, string>();

    for (const row of rows) {
        if (!codes.has(row.raw_material_id)) {
            codes.set(
                row.raw_material_id,
                row.raw_material_name ?? `MP #${row.raw_material_id}`,
            );
        }
    }

    return codes;
}

/** El nombre de cada producto de la orden por id, en el orden de la tabla (agregados al final). */
export function productNamesOf(
    lines: ProductionOrderProductLine[],
): Map<number, string> {
    const names = new Map<number, string>();

    for (const line of lines) {
        if (!names.has(line.product_id)) {
            names.set(
                line.product_id,
                line.product_name ?? `PT #${line.product_id}`,
            );
        }
    }

    return names;
}

/** Las materias primas que se pasaron, como filas del bloque de motivos. */
export function materialReasonRows(
    rows: ProductionOrderOverconsumption[],
): OverconsumptionReasonRow[] {
    return rows.map((row) => ({
        ...row,
        id: row.raw_material_id,
        label: row.code,
    }));
}

/** Los productos terminados que se pasaron, como filas del bloque de motivos. */
export function productReasonRows(
    rows: ProductionOrderProductOverconsumption[],
): OverconsumptionReasonRow[] {
    return rows.map((row) => ({
        ...row,
        id: row.product_id,
        label: row.name,
    }));
}
