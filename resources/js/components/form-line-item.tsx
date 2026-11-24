import { ChevronDown, Trash2 } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type FormLineItemProps = {
    /** Posición de la línea en la lista (desde 0): el encabezado dice «Línea 1», «Línea 2»… */
    index: number;
    /** El nombre de la línea en el encabezado del celular: «Línea» o, en una fórmula, «Paso». */
    label?: string;
    /** Quita la línea. Sin él no hay botón (por ejemplo, cuando queda una sola línea obligatoria). */
    onRemove?: () => void;
    /**
     * Desde `md`, las líneas después de la primera esconden sus etiquetas (siguen para el lector de pantalla): la
     * primera fila hace de encabezado de columnas. Para editores con muchas líneas cortas (fórmula, plan de envasado).
     */
    labelsOnFirstRowOnly?: boolean;
    /** Clases del contenedor de campos: las columnas desde `md` (`md:grid-cols-12`). */
    className?: string;
    /** Lo que va bajo los campos, a todo el ancho (el subtotal de la línea). */
    footer?: ReactNode;
    /**
     * Campos secundarios (opcional). En el celular van plegados bajo un botón (`secondaryLabel`) con un resumen
     * (`secondarySummary`); desde `md`, siempre a la vista en una segunda fila. Se quedan montados aunque estén plegados
     * (solo se esconden): conservan lo escrito y el foco puede llegar a ellos al abrirlos.
     */
    secondary?: ReactNode;
    /** Texto del botón que pliega los secundarios en el celular. */
    secondaryLabel?: string;
    /** Resumen de los secundarios que se ve con el grupo cerrado: «Imprimante · RAL 7035 · Ajuste -5%». */
    secondarySummary?: string;
    /** Abierto o cerrado en el celular (controlado). Quien lo usa lo abre también cuando un secundario tiene error. */
    secondaryOpen?: boolean;
    onSecondaryOpenChange?: (open: boolean) => void;
    /** Clases de la fila de secundarios desde `md` (`md:grid-cols-12`). */
    secondaryClassName?: string;
    children: ReactNode;
};

/**
 * Una línea de un editor de líneas (DESIGN.md §8): fórmula, plan de envasado de la OP, cotización y pedido. En el
 * celular es un bloque con el encabezado «Línea N», el botón de quitar y los campos uno debajo del otro; desde `md`,
 * los campos van en una fila con el botón de quitar al final. Cada campo es un `<FormField>` con su `md:col-span-*`.
 */
export function FormLineItem({
    index,
    label = 'Línea',
    onRemove,
    labelsOnFirstRowOnly = false,
    className,
    footer,
    secondary,
    secondaryLabel = 'Más detalles',
    secondarySummary,
    secondaryOpen = false,
    onSecondaryOpenChange,
    secondaryClassName,
    children,
}: FormLineItemProps) {
    const title = `${label} ${index + 1}`;
    const secondaryId = useId();

    return (
        <div
            data-slot="form-line-item"
            className={cn(
                'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-lg border border-border p-4',
                labelsOnFirstRowOnly &&
                    index > 0 &&
                    'md:[&_[data-slot=label]]:sr-only',
            )}
        >
            <p className="text-sm font-medium text-foreground md:hidden">
                {title}
            </p>
            {onRemove && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={onRemove}
                    aria-label={`Quitar ${title.toLowerCase()}`}
                    className="col-start-2 row-start-1 justify-self-end text-destructive hover:bg-destructive/10 hover:text-destructive md:self-end"
                >
                    <Trash2 />
                </Button>
            )}
            <div
                className={cn(
                    'col-span-2 grid min-w-0 gap-4 md:col-span-1 md:col-start-1 md:row-start-1',
                    className,
                )}
            >
                {children}
            </div>
            {secondary && (
                <>
                    {/* Solo en el celular: desde `md` los secundarios siempre se ven. */}
                    <div className="col-span-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 md:hidden">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-expanded={secondaryOpen}
                            aria-controls={secondaryId}
                            onClick={() =>
                                onSecondaryOpenChange?.(!secondaryOpen)
                            }
                            className="-ml-3"
                        >
                            {secondaryLabel}
                            <ChevronDown
                                className={cn(
                                    'transition-transform',
                                    secondaryOpen && 'rotate-180',
                                )}
                            />
                        </Button>
                        {!secondaryOpen && secondarySummary && (
                            <span className="min-w-0 truncate text-xs text-muted-foreground">
                                {secondarySummary}
                            </span>
                        )}
                    </div>
                    <div
                        id={secondaryId}
                        className={cn(
                            'col-span-2 grid min-w-0 gap-4 md:col-span-1 md:col-start-1',
                            !secondaryOpen && 'max-md:hidden',
                            secondaryClassName,
                        )}
                    >
                        {secondary}
                    </div>
                </>
            )}
            {footer && <div className="col-span-2">{footer}</div>}
        </div>
    );
}
