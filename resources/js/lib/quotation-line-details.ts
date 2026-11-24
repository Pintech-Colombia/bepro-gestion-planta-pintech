import { formatKind } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';

/**
 * Los campos secundarios de una línea de cotización: en el celular van plegados en «Más detalles» (DESIGN.md §8, «Line
 * editors»); desde `md`, en la segunda fila de la línea.
 */
export const QUOTATION_SECONDARY_FIELDS = [
    'type',
    'description',
    'color',
    'price_adjustment_pct',
] as const;

export type QuotationSecondaryField =
    (typeof QUOTATION_SECONDARY_FIELDS)[number];

/**
 * Si algún campo secundario de la línea tiene error (del servidor o de la vista previa). Con error, «Más detalles» se
 * abre en el mismo render, para que `focusFirstError` encuentre el campo visible.
 */
export function lineHasSecondaryError(
    errorFor: (field: QuotationSecondaryField) => string | undefined,
): boolean {
    return QUOTATION_SECONDARY_FIELDS.some((field) => Boolean(errorFor(field)));
}

/**
 * El resumen de «Más detalles» cerrado: lo que tiene valor, separado por « · ». El ajuste va solo si no es cero, para
 * que un descuento nunca quede escondido: «Imprimante · RAL 7035 · Ajuste -5%». La descripción no entra (es larga).
 */
export function quotationLineSummary(details: {
    typeLabel?: string | null;
    color?: string | null;
    adjustment?: string | number | null;
}): string {
    const parts: string[] = [];

    if (details.typeLabel?.trim()) {
        parts.push(details.typeLabel.trim());
    }

    if (details.color?.trim()) {
        parts.push(details.color.trim());
    }

    // El campo guarda lo que se escribe, con coma decimal («-7,5»); el servidor lo devuelve con punto («-7.5»).
    // `parseDecimal` entiende los dos.
    const adjustment = parseDecimal(details.adjustment ?? null);

    if (adjustment !== null && adjustment !== 0) {
        parts.push(`Ajuste ${formatKind(adjustment, 'percent')}`);
    }

    return parts.join(' · ');
}
