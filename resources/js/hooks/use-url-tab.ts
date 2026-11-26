import { router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

import { buildUrlTabHref } from '@/lib/url-tab';

type UseUrlTabOptions<T extends string> = {
    /** Nombre del parámetro en la query string (p. ej. `lotes`). */
    param: string;
    /** Pestañas disponibles; un valor fuera de esta lista se ignora. */
    tabs: readonly T[];
    /** Pestaña por defecto (se omite de la URL). */
    defaultTab: T;
    /** Pestaña activa validada por el servidor (la prop que devuelve el controlador). */
    value: T;
    /** Parámetros a limpiar al cambiar de pestaña (p. ej. `['lotes_page']`). */
    clearParams?: readonly string[];
    /** Props de Inertia que se recargan al cambiar de pestaña (`only`). */
    reloadOnly: string[];
};

/**
 * Pestañas dentro de una misma página sincronizadas con un parámetro de la URL (enlace directo, recarga y Atrás).
 * Al hacer clic la pestaña cambia de inmediato (optimista) y se piden solo las props de `reloadOnly`; `isLoading`
 * queda en `true` hasta que responde el servidor.
 */
export function useUrlTab<T extends string>({
    param,
    tabs,
    defaultTab,
    value,
    clearParams,
    reloadOnly,
}: UseUrlTabOptions<T>) {
    const page = usePage();
    const [pendingTab, setPendingTab] = useState<T | null>(null);
    const currentTab: T = pendingTab ?? value;

    const setTab = useCallback(
        (rawNext: string) => {
            if (!(tabs as readonly string[]).includes(rawNext)) {
                return;
            }

            const nextTab = rawNext as T;

            if (nextTab === currentTab) {
                return;
            }

            const currentHref =
                typeof window !== 'undefined'
                    ? `${window.location.pathname}${window.location.search}`
                    : page.url;

            setPendingTab(nextTab);
            router.get(
                buildUrlTabHref(currentHref, {
                    param,
                    value: nextTab,
                    defaultValue: defaultTab,
                    clearParams,
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: reloadOnly,
                    onFinish: () => {
                        setPendingTab((current) =>
                            current === nextTab ? null : current,
                        );
                    },
                },
            );
        },
        [
            clearParams,
            currentTab,
            defaultTab,
            page.url,
            param,
            reloadOnly,
            tabs,
        ],
    );

    return {
        tab: currentTab,
        setTab,
        isLoading: pendingTab !== null,
    };
}
