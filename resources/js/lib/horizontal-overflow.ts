export type HorizontalScrollMetrics = {
    scrollLeft: number;
    scrollWidth: number;
    clientWidth: number;
};

export type HorizontalOverflowState = {
    /** El contenido supera el ancho visible de la caja. */
    overflows: boolean;
    /** Queda contenido oculto hacia la izquierda. */
    canScrollLeft: boolean;
    /** Queda contenido oculto hacia la derecha. */
    canScrollRight: boolean;
};

export type RevealItemMetrics = HorizontalScrollMetrics & {
    /** Posición horizontal del elemento respecto al inicio del contenido desplazable. */
    itemStart: number;
    itemWidth: number;
    /** Margen visual a cada lado (p. ej. el desvanecido) para no dejar el elemento tapado. */
    padding?: number;
};

/**
 * Tolerancia de 1 px para absorber redondeos de subpíxeles (zoom o pantallas Retina):
 * sin ella, al llegar al borde derecho un desvanecido puede quedarse encendido.
 */
const EDGE_TOLERANCE_PX = 1;

/** Calcula si una caja se desborda a lo ancho y hacia qué lados queda contenido por desplazar. */
export function measureHorizontalOverflow({
    scrollLeft,
    scrollWidth,
    clientWidth,
}: HorizontalScrollMetrics): HorizontalOverflowState {
    if (clientWidth <= 0) {
        return {
            overflows: false,
            canScrollLeft: false,
            canScrollRight: false,
        };
    }

    const maxScroll = Math.max(0, scrollWidth - clientWidth);
    const overflows = maxScroll > EDGE_TOLERANCE_PX;

    if (!overflows) {
        return {
            overflows: false,
            canScrollLeft: false,
            canScrollRight: false,
        };
    }

    const clampedScroll = Math.min(Math.max(0, scrollLeft), maxScroll);

    return {
        overflows: true,
        canScrollLeft: clampedScroll > EDGE_TOLERANCE_PX,
        canScrollRight: maxScroll - clampedScroll > EDGE_TOLERANCE_PX,
    };
}

/**
 * Calcula el `scrollLeft` mínimo necesario para dejar visible un elemento dentro de su caja
 * sin tocar el desplazamiento de la página. Si ya está a la vista, conserva la posición actual.
 */
export function scrollLeftToRevealItem({
    scrollLeft,
    clientWidth,
    scrollWidth,
    itemStart,
    itemWidth,
    padding = 0,
}: RevealItemMetrics): number {
    const maxScroll = Math.max(0, scrollWidth - clientWidth);

    if (maxScroll <= 0 || clientWidth <= 0) {
        return 0;
    }

    const clamp = (value: number) => Math.min(Math.max(0, value), maxScroll);

    // Si el elemento supera el ancho de la caja (p. ej. zoom alto), prioriza siempre el inicio del texto.
    if (itemWidth >= clientWidth) {
        return clamp(itemStart);
    }

    const currentScroll = clamp(scrollLeft);
    const effectivePadding = Math.min(
        Math.max(0, padding),
        Math.max(0, (clientWidth - itemWidth) / 2),
    );
    const visibleStart = currentScroll + effectivePadding;
    const visibleEnd = currentScroll + clientWidth - effectivePadding;
    const itemEnd = itemStart + itemWidth;

    if (itemStart < visibleStart) {
        return clamp(itemStart - effectivePadding);
    }

    if (itemEnd > visibleEnd) {
        return clamp(itemEnd - clientWidth + effectivePadding);
    }

    return currentScroll;
}
