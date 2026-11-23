import { formatForInput, formatKind } from '@/lib/formatters';
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
 * Cuántas unidades del lote implican los kilos gastados (de una línea que sale de un solo lote), solo para mostrar (el servidor saca del inventario las unidades de
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
 * Si con menos unidades alcanzan los kilos gastados (más lo que quedó en el envase abierto), sugiere bajarlas: las que no
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
 * Aviso, sin bloquear: lo gastado de un producto más lo que quedó en envases abiertos pasa de lo que traen sus fuentes
 * (los kilos de sus saldos + unidades × kilos por unidad de sus lotes). Un llenado de más es posible (el peso por unidad
 * es teórico), así que se puede enviar igual. `null` si no pasa o faltan datos.
 */
export function lineOverfillWarning(
    actualKg: FormValue,
    leftoverKg: FormValue,
    coveredKg: FormValue,
): string | null {
    const used = toTenThousandths(actualKg);
    const leftover = Math.max(toTenThousandths(leftoverKg) ?? 0, 0);
    const covered = toTenThousandths(coveredKg);

    if (used === null || covered === null || used <= 0 || covered <= 0) {
        return null;
    }

    const total = used + leftover;

    if (total <= covered) {
        return null;
    }

    const what = leftover > 0 ? 'Lo gastado más lo que quedó' : 'Lo gastado';

    return `${what} (${kilos(total)}) pasa de lo que traen sus fuentes (${kilos(covered)}). Revisa los kilos o las fuentes; si están bien, puedes seguir.`;
}

/**
 * Lo que el buscador propone al elegir un saldo: los kilos que le faltan a la línea, o todo lo libre si tiene menos.
 * Vacío si no se sabe cuánto falta (línea agregada) o ya no falta nada: la persona escribe.
 */
export function proposedRemnantKg(
    missingKg: FormValue,
    freeKg: FormValue,
): string {
    const missing = toTenThousandths(missingKg);
    const free = toTenThousandths(freeKg);

    if (missing === null || free === null || missing <= 0 || free <= 0) {
        return '';
    }

    return formatForInput(String(Math.min(missing, free) / SCALE));
}

/**
 * Lo que el buscador propone al elegir un lote: las unidades enteras que cubren lo que le falta a la línea (techo), sin
 * pasar de las libres. Vacío si no se sabe cuánto falta, ya no falta nada o el lote no tiene peso por galón.
 */
export function proposedBatchUnits(
    missingKg: FormValue,
    kgPerUnit: FormValue,
    freeUnits: FormValue,
): string {
    const missing = toTenThousandths(missingKg);
    const perUnit = toTenThousandths(kgPerUnit);
    const free = parseDecimal(freeUnits);

    if (
        missing === null ||
        perUnit === null ||
        free === null ||
        missing <= 0 ||
        perUnit <= 0 ||
        free <= 0
    ) {
        return '';
    }

    return String(Math.min(Math.ceil(missing / perUnit), Math.floor(free)));
}
