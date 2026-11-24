import { router } from '@inertiajs/react';
import { useState } from 'react';

import { RowActions } from '@/components/row-actions';
import type { RowAction } from '@/components/row-actions';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@/hooks/use-confirm';
import type { Tone } from '@/types/enums';

/** Una transición como la manda el servidor: `EnumOptions::for($status->nextTransitions())`. */
export type StatusTransition = {
    value: string;
    label: string;
    tone?: Tone;
};

/** Textos de una transición. Viven en la pantalla: el servidor solo manda a qué estados se puede pasar. */
export type TransitionCopy = {
    /** Texto del botón: «Marcar como enviada», «Cancelar pedido». */
    label: string;
    /** Pregunta del diálogo: «¿Marcar la cotización como enviada?». */
    title: string;
    /** Qué pasa al confirmar. */
    description: string;
    /** Texto del botón de confirmar; por defecto, el del botón. */
    confirmLabel?: string;
};

type StatusTransitionsProps = {
    transitions: StatusTransition[];
    /**
     * Textos de cada transición según el estado destino. Sin texto para un estado (`undefined`), se usa la etiqueta que
     * manda el servidor, nunca el código del estado.
     */
    copy: (value: string) => TransitionCopy | undefined;
    /** URL del cambio de estado (`PATCH` con `{ status }`). */
    url: string;
    /** Nombre del registro para el menú «⋯» del celular: «Acciones de la cotización No. 57». */
    itemLabel: string;
    /** Acciones que en el celular van en el mismo menú «⋯» (p. ej. «Editar»). */
    mobileExtraActions?: RowAction[];
};

/**
 * Botones de cambio de estado de un documento (DESIGN.md §4 y §10), cada uno con confirmación. En el computador van
 * como botones en `PageHeader.actions`; en el celular, en un menú «⋯» junto con `mobileExtraActions`, para no llenar el
 * encabezado. Una transición con tono `danger` (rechazar, cancelar) confirma en rojo. Los errores del servidor llegan en
 * `errors.status`: la pantalla los muestra con `<FormError id="status">`.
 */
export function StatusTransitions({
    transitions,
    copy,
    url,
    itemLabel,
    mobileExtraActions = [],
}: StatusTransitionsProps) {
    const confirm = useConfirm();
    const [processing, setProcessing] = useState(false);

    const textFor = (transition: StatusTransition): TransitionCopy =>
        copy(transition.value) ?? {
            label: transition.label,
            title: `¿Pasar a «${transition.label}»?`,
            description: `El estado cambia a «${transition.label}».`,
        };

    const run = async (transition: StatusTransition) => {
        if (processing) {
            return;
        }

        const text = textFor(transition);
        const destructive = transition.tone === 'danger';

        const confirmed = await confirm({
            title: text.title,
            description: text.description,
            confirmLabel: text.confirmLabel ?? text.label,
            destructive,
        });

        if (!confirmed) {
            return;
        }

        router.patch(
            url,
            { status: transition.value },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    // Las que anulan algo van al final: primero lo que hace avanzar el documento.
    const ordered = [
        ...transitions.filter((transition) => transition.tone !== 'danger'),
        ...transitions.filter((transition) => transition.tone === 'danger'),
    ];

    const mobileActions: RowAction[] = [
        ...mobileExtraActions,
        ...ordered.map((transition): RowAction => ({
            label: textFor(transition).label,
            destructive: transition.tone === 'danger',
            onSelect: () => void run(transition),
            // Mientras se guarda un cambio, el menú del celular tampoco deja mandar otro.
            disabledReason: processing
                ? 'Se está guardando un cambio de estado.'
                : undefined,
        })),
    ];

    return (
        <>
            {ordered.map((transition) => (
                <Button
                    key={transition.value}
                    type="button"
                    variant="outline"
                    className="hidden md:inline-flex"
                    disabled={processing}
                    onClick={() => void run(transition)}
                >
                    {textFor(transition).label}
                </Button>
            ))}
            <RowActions
                actions={mobileActions}
                itemLabel={itemLabel}
                className="md:hidden"
            />
        </>
    );
}
