import { useCallback, useRef, useState } from 'react';

import { measureHorizontalOverflow } from '@/lib/horizontal-overflow';
import type { HorizontalOverflowState } from '@/lib/horizontal-overflow';

type UseHorizontalOverflowOptions = {
    /**
     * Cuando el `ref` va en el contenido (p. ej. `<table>` dentro de `ui/table.tsx`) y quien se desplaza
     * es su contenedor padre.
     */
    observeParent?: boolean;
    /** Escucha el evento `scroll` para mantener al día `canScrollLeft` y `canScrollRight`. */
    trackScroll?: boolean;
};

const initialOverflowState: HorizontalOverflowState = {
    overflows: false,
    canScrollLeft: false,
    canScrollRight: false,
};

/**
 * Detecta si una caja con desplazamiento lateral se desborda y hacia qué lados queda contenido.
 * Comparte un único `ResizeObserver` (caja + contenido) entre `DetailTable` y `NavTabs`.
 *
 * Nota: con `observeParent = false` (por defecto), observa `node` y `node.firstElementChild`, por lo que
 * la caja desplazable debe envolver sus elementos dentro de un único hijo (p. ej. un contenedor `w-max`).
 */
export function useHorizontalOverflow<T extends HTMLElement = HTMLElement>({
    observeParent = false,
    trackScroll = true,
}: UseHorizontalOverflowOptions = {}) {
    const [state, setState] =
        useState<HorizontalOverflowState>(initialOverflowState);
    const containerRef = useRef<HTMLElement | null>(null);

    const measure = useCallback(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const next = measureHorizontalOverflow({
            scrollLeft: container.scrollLeft,
            scrollWidth: container.scrollWidth,
            clientWidth: container.clientWidth,
        });

        setState((current) =>
            current.overflows === next.overflows &&
            current.canScrollLeft === next.canScrollLeft &&
            current.canScrollRight === next.canScrollRight
                ? current
                : next,
        );
    }, []);

    const ref = useCallback(
        (node: T | null) => {
            const container = observeParent ? node?.parentElement : node;
            const content = observeParent ? node : node?.firstElementChild;

            if (!node || !container) {
                containerRef.current = null;

                return;
            }

            containerRef.current = container;
            measure();

            const observer =
                typeof ResizeObserver !== 'undefined'
                    ? new ResizeObserver(measure)
                    : null;

            if (observer) {
                observer.observe(container);

                if (content && content !== container) {
                    observer.observe(content);
                }
            }

            if (trackScroll) {
                container.addEventListener('scroll', measure, {
                    passive: true,
                });
            }

            return () => {
                containerRef.current = null;
                observer?.disconnect();

                if (trackScroll) {
                    container.removeEventListener('scroll', measure);
                }
            };
        },
        [measure, observeParent, trackScroll],
    );

    return {
        ref,
        measure,
        ...state,
    };
}
