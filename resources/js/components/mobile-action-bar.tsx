import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

import { opensKeyboard } from '@/lib/mobile-keyboard';
import type { FocusableElementLike } from '@/lib/mobile-keyboard';
import { cn } from '@/lib/utils';

type MobileActionBarProps = {
    /** Resumen opcional a la izquierda (p. ej. el total de una cotización). */
    summary?: ReactNode;
    /** Acción principal a la derecha, o a todo el ancho si es la única acción sin `summary`. */
    children: ReactNode;
    className?: string;
};

/**
 * Barra fija inferior solo para el celular (`< md`, DESIGN.md §10). Comparte el estilo del encabezado fijo y vive en
 * `z-40`, debajo de diálogos, cajones, popovers y avisos (`z-50`). Se oculta mientras el foco está en un campo que abre
 * el teclado virtual y decide en el siguiente cuadro tras `focusout` para no parpadear entre campos.
 */
export function MobileActionBar({
    summary,
    children,
    className,
}: MobileActionBarProps) {
    const [keyboardOpen, setKeyboardOpen] = useState(false);
    const rafIdRef = useRef<number | null>(null);

    useEffect(() => {
        if (typeof document === 'undefined') {
            return;
        }

        const cancelPendingCheck = () => {
            if (rafIdRef.current !== null) {
                window.cancelAnimationFrame(rafIdRef.current);
                rafIdRef.current = null;
            }
        };

        const syncFromActiveElement = () => {
            setKeyboardOpen(
                opensKeyboard(
                    document.activeElement as FocusableElementLike | null,
                ),
            );
        };

        const handleFocusIn = (event: FocusEvent) => {
            cancelPendingCheck();
            const target = event.target as FocusableElementLike | null;
            setKeyboardOpen(
                opensKeyboard(target) ||
                    opensKeyboard(
                        document.activeElement as FocusableElementLike | null,
                    ),
            );
        };

        const handleFocusOut = () => {
            cancelPendingCheck();
            rafIdRef.current = window.requestAnimationFrame(() => {
                rafIdRef.current = null;
                syncFromActiveElement();
            });
        };

        syncFromActiveElement();
        document.addEventListener('focusin', handleFocusIn);
        document.addEventListener('focusout', handleFocusOut);

        return () => {
            cancelPendingCheck();
            document.removeEventListener('focusin', handleFocusIn);
            document.removeEventListener('focusout', handleFocusOut);
        };
    }, []);

    return (
        <div
            data-slot="mobile-action-bar"
            className={cn(
                'fixed inset-x-0 bottom-0 z-40 border-t border-border/80 bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/85 md:hidden',
                keyboardOpen && 'hidden',
                className,
            )}
        >
            {summary ? (
                <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0 flex-1">{summary}</div>
                    <div className="flex shrink-0 items-center gap-2">
                        {children}
                    </div>
                </div>
            ) : (
                <div className="flex items-center justify-end gap-2 *:only:w-full">
                    {children}
                </div>
            )}
        </div>
    );
}
