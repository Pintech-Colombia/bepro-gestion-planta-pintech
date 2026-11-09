import { useCallback } from 'react';

import { useSidebar } from '@/components/ui/sidebar';

export type CleanupFn = () => void;

/**
 * Lo que hay que hacer al tocar un enlace del menú lateral o del menú de usuario. Úsalo en el `onClick` de cada enlace
 * que navega desde el menú: es el único lugar donde se cierra el panel del celular.
 */
export function useMobileNavigation(): CleanupFn {
    const { setOpenMobile } = useSidebar();

    return useCallback(() => {
        // En el celular el menú es un panel encima de la página: si no se cierra al navegar, sigue tapando la pantalla
        // nueva. En el escritorio no hace nada.
        setOpenMobile(false);
        // Radix deja `pointer-events: none` en el body si el menú desplegable se cierra junto con la navegación.
        document.body.style.removeProperty('pointer-events');
    }, [setOpenMobile]);
}
