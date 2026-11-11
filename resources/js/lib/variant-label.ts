import { formatKind } from '@/lib/formatters';

/**
 * Presentación con su contenido en galones, para elegirla en el plan de envasado de una OP: «Galón (1 gal)». El
 * producto ya está elegido, así que no se repite.
 */
export function packagingVariantLabel(variant: {
    presentation_label: string;
    presentation_value: number | string | null;
}): string {
    if (variant.presentation_value === null) {
        return variant.presentation_label;
    }

    return `${variant.presentation_label} (${formatKind(variant.presentation_value, 'quantity')} gal)`;
}
