import type { ReactNode } from 'react';

import { pillClasses, toneClasses } from '@/lib/tones';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types/enums';

type ToneBadgeProps = {
    tone?: Tone;
    children: ReactNode;
    className?: string;
    /** Explicación al pasar el mouse. */
    title?: string;
};

/**
 * Pastilla con uno de los cinco tonos aprobados (DESIGN.md §4) para etiquetas informativas que no
 * provienen de un enum PHP (p. ej. «Se consume primero» en lotes FIFO). Las pantallas nunca arman
 * clases de tono a mano.
 */
export function ToneBadge({
    tone = 'neutral',
    children,
    className,
    title,
}: ToneBadgeProps) {
    return (
        <span
            className={cn(pillClasses, toneClasses[tone], className)}
            title={title}
        >
            {children}
        </span>
    );
}
