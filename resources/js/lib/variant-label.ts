import { formatKind } from '@/lib/formatters';

/**
 * Presentación con su contenido en galones: «Galón (1 gal)». Va donde el producto ya se ve al lado (plan de envasado,
 * inventario de PT, bodega), así que no se repite.
 */
export function variantPresentationLabel(variant: {
    presentation_label: string;
    presentation_value: number | string | null;
}): string {
    if (variant.presentation_value === null) {
        return variant.presentation_label;
    }

    return `${variant.presentation_label} (${formatKind(variant.presentation_value, 'quantity')} gal)`;
}
