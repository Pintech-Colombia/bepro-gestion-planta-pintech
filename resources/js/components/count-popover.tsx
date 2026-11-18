import type { ReactNode } from 'react';

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

type CountPopoverProps = {
    /** Texto del botón, con el recuento («3 atributos modificados», «2 lotes»). Es su nombre accesible. */
    label: ReactNode;
    /** Nombre de la ventana para el lector de pantalla (Radix la pinta como `dialog`). */
    contentLabel: string;
    children: ReactNode;
};

/**
 * Un recuento que abre su lista en una ventana: se abre con un toque o un clic (también en el celular) y se monta
 * fuera de la tabla, dentro de la pantalla. Una lista larga se desplaza dentro de la ventana.
 */
export function CountPopover({
    label,
    contentLabel,
    children,
}: CountPopoverProps) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center rounded-md border border-border bg-muted/40 px-2 py-1 text-xs font-medium text-muted-foreground transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/50 data-[state=open]:bg-muted"
                >
                    {label}
                </button>
            </PopoverTrigger>
            <PopoverContent
                aria-label={contentLabel}
                align="start"
                collisionPadding={8}
                className="max-h-(--radix-popover-content-available-height) w-72 overflow-y-auto p-3 sm:w-96"
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}
