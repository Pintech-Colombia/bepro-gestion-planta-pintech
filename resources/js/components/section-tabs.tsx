import { Tabs as TabsPrimitive } from 'radix-ui';
import type { ComponentProps } from 'react';

import {
    tabBarClasses,
    tabItemClasses,
    tabItemInactiveClasses,
} from '@/lib/tabs';
import { cn } from '@/lib/utils';

/**
 * Lista de pestañas de sección (paneles grandes dentro de una página, DESIGN.md §10), con el mismo estilo de línea
 * inferior que `<NavTabs>` (`lib/tabs.ts`). Va dentro de `<Tabs>` de `ui/tabs.tsx`, cuyo `TabsList` (caja gris) queda
 * solo para selectores segmentados de 2–3 modos dentro de un control pequeño.
 */
export function SectionTabsList({
    className,
    ...props
}: ComponentProps<typeof TabsPrimitive.List>) {
    return (
        <div className={cn(tabBarClasses, className)}>
            <TabsPrimitive.List
                data-slot="tabs-list"
                className="relative -mb-px flex [scrollbar-width:none] items-center gap-1 overflow-x-auto [&::-webkit-scrollbar]:hidden"
                {...props}
            />
        </div>
    );
}

/**
 * Radix enfoca la pestaña por código en el `mousedown` (`@radix-ui/react-tabs`), y Chrome decide si ese foco es
 * «visible» según lo que estaba enfocado antes: si no había nada (página recién abierta o clic en una zona vacía),
 * pinta el anillo de foco aunque el clic fuera con el mouse. La marca `data-pointer-focus` (puesta en `pointerdown`,
 * antes del foco, y quitada en `blur`) apaga el anillo solo en ese caso; con Tab o flechas sigue saliendo.
 */
export function SectionTabsTrigger({
    className,
    onPointerDown,
    onBlur,
    ...props
}: ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            data-slot="tabs-trigger"
            className={cn(
                tabItemClasses,
                tabItemInactiveClasses,
                'cursor-pointer disabled:pointer-events-none disabled:opacity-50 data-[state=active]:text-foreground data-[state=active]:after:bg-foreground [&[data-pointer-focus]:focus-visible]:ring-0',
                className,
            )}
            onPointerDown={(event) => {
                event.currentTarget.dataset.pointerFocus = '';
                onPointerDown?.(event);
            }}
            onBlur={(event) => {
                delete event.currentTarget.dataset.pointerFocus;
                onBlur?.(event);
            }}
            {...props}
            data-touch-target="default"
        />
    );
}
