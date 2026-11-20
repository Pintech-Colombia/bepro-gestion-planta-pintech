import { formatKind } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';

type FormValue = string | number | null | undefined;

/** Los kilos llevan 4 decimales: las cuentas van en diezmilésimas, con enteros, para que 468 ÷ 23,4 dé 20 y no 19,999… */
const SCALE = 10_000;

function toTenThousandths(value: FormValue): number | null {
    const parsed = parseDecimal(value);

    return parsed === null ? null : Math.round(parsed * SCALE);
}

function kilos(tenThousandths: number): string {
    return `${formatKind(tenThousandths / SCALE, 'quantity')} kg`;
}

function unitsText(units: number): string {
    return units === 1 ? '1 unidad' : `${formatKind(units, 'units')} unidades`;
}

/**
 * Cuántas unidades del lote implican los kilos usados, solo para mostrar (el servidor saca del inventario las unidades de
 * la fuente, no estas): «462,3 kg ≈ 19 unidades + 17,7 kg de la 20». `null` si faltan los kilos o los kilos por unidad.
 */
export function batchUsageSummary(
    usedKg: FormValue,
    kgPerUnit: FormValue,
): string | null {
    const used = toTenThousandths(usedKg);
    const perUnit = toTenThousandths(kgPerUnit);

    if (used === null || perUnit === null || used <= 0 || perUnit <= 0) {
        return null;
    }

    const fullUnits = Math.floor(used / perUnit);
    const rest = used - fullUnits * perUnit;

    if (rest === 0) {
        return `${kilos(used)} = ${unitsText(fullUnits)}`;
    }

    if (fullUnits === 0) {
        return `${kilos(used)}: menos de una unidad`;
    }

    return `${kilos(used)} ≈ ${unitsText(fullUnits)} + ${kilos(rest)} de la ${formatKind(fullUnits + 1, 'units')}`;
}

/**
 * Si con menos unidades alcanzan los kilos usados (más lo que quedó en el envase abierto), sugiere bajarlas: las que no
 * se abrieron vuelven a bodega. `null` si las unidades de la fuente son justas o faltan datos.
 */
export function batchUnitsSuggestion(
    usedKg: FormValue,
    leftoverKg: FormValue,
    kgPerUnit: FormValue,
    units: number,
): string | null {
    const used = toTenThousandths(usedKg);
    const leftover = toTenThousandths(leftoverKg) ?? 0;
    const perUnit = toTenThousandths(kgPerUnit);

    if (used === null || perUnit === null || used <= 0 || perUnit <= 0) {
        return null;
    }

    const needed = Math.ceil((used + Math.max(leftover, 0)) / perUnit);

    if (needed < 1 || needed >= units) {
        return null;
    }

    const spare = units - needed;
    const spareText =
        spare === 1
            ? 'si la otra no se abrió, baja las unidades del lote y vuelve'
            : `si las otras ${formatKind(spare, 'units')} no se abrieron, baja las unidades del lote y vuelven`;

    return `Con ${unitsText(needed)} alcanza: ${spareText} a bodega.`;
}

/**
 * Aviso, sin bloquear: los kilos usados más lo que quedó pasan de lo que traen las unidades de la fuente (unidades × kilos
 * por unidad). Un llenado de más es posible (el peso por unidad es teórico), así que se puede enviar igual. `null` si no
 * pasan o faltan datos.
 */
export function batchOverfillWarning(
    usedKg: FormValue,
    leftoverKg: FormValue,
    kgPerUnit: FormValue,
    units: number,
): string | null {
    const used = toTenThousandths(usedKg);
    const leftover = Math.max(toTenThousandths(leftoverKg) ?? 0, 0);
    const perUnit = toTenThousandths(kgPerUnit);

    if (used === null || perUnit === null || used <= 0 || perUnit <= 0) {
        return null;
    }

    const total = used + leftover;
    const capacity = units * perUnit;

    if (total <= capacity) {
        return null;
    }

    const what =
        leftover > 0 ? 'Los kilos usados más lo que quedó' : 'Los kilos usados';

    return `${what} (${kilos(total)}) pasan de lo que traen ${unitsText(units)} (${kilos(capacity)}). Revisa los kilos o las unidades; si están bien, puedes seguir.`;
}
