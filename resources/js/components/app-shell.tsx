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
 * abierto tapando la pantalla nueva. Un solo oyente cubre los enlaces del menú, los que se agreguen y el botón Atrás.
 * `navigate` y no `before`: Atrás no pasa por `before`, y una recarga de la misma URL (las parciales de los cajones)
 * no dispara `navigate`, así que no cierra el menú (mismo criterio que `ConfirmProvider`).
 */
function CloseMobileSidebarOnNavigate() {
    const { setOpenMobile } = useSidebar();

    useEffect(
        () =>
            router.on('navigate', () => {
                setOpenMobile(false);
            }),
        [setOpenMobile],
    );

    return null;
}
