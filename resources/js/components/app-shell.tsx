import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { SidebarProvider, useSidebar } from '@/components/ui/sidebar';

type Props = {
    children: ReactNode;
};

export function AppShell({ children }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    return (
        <SidebarProvider defaultOpen={isOpen}>
            <CloseMobileSidebarOnNavigate />
            {children}
        </SidebarProvider>
    );
}

/**
 * En el celular el menú es un panel encima de la página, y el layout persiste entre páginas: sin esto, el panel sigue
 * abierto tapando la pantalla nueva. Dos oyentes globales cubren los enlaces del menú, los que se agreguen y el botón
 * Atrás:
 * - `start`: cierra al tocar, sin esperar al servidor (con la red lenta de la planta, el panel no se queda sobre la
 *   página vieja mientras carga la nueva). También cubre el enlace de la página actual: una visita a la misma URL
 *   reemplaza la página y no dispara `navigate`. Las visitas parciales (`only`, `except` o `reset`, las de los cajones)
 *   no lo cierran.
 * - `navigate`: solo para Atrás, que restaura la página del historial sin pasar por `start`.
 * `ConfirmProvider` escucha solo `navigate` a propósito: ahí la pregunta se cancela solo al irse de verdad.
 */
function CloseMobileSidebarOnNavigate() {
    const { setOpenMobile } = useSidebar();

    useEffect(() => {
        const removeStartListener = router.on('start', (event) => {
            const { only, except, reset } = event.detail.visit;

            if (only.length > 0 || except.length > 0 || reset.length > 0) {
                return;
            }

            setOpenMobile(false);
        });
        const removeNavigateListener = router.on('navigate', () => {
            setOpenMobile(false);
        });

        return () => {
            removeStartListener();
            removeNavigateListener();
        };
    }, [setOpenMobile]);

    return null;
}
