import { useCallback } from 'react';

export type CleanupFn = () => void;

/**
 * Devuelve una función que quita `pointer-events: none` del `body`. Radix lo pone mientras un desplegable está abierto
 * y, si el desplegable se cierra junto con una navegación (un enlace del menú de usuario), puede quedar puesto y la
 * página deja de responder a los clics.
 */
export function useReleaseBodyPointerEvents(): CleanupFn {
    return useCallback(() => {
        document.body.style.removeProperty('pointer-events');
    }, []);
}
