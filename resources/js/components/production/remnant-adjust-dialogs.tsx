import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { DecimalInput } from '@/components/decimal-input';
import { FormActions } from '@/components/form-actions';
import { FormError, FormField } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { NUMBER_TYPES } from '@/lib/number-types';
import { toDecimalPayload } from '@/lib/numeric-input';
import {
    correct as remnantCorrect,
    writeOff as remnantWriteOff,
} from '@/routes/production/remnants';

/** Mínimo y máximo de la nota: los mismos del servidor (`WriteOffRemnantRequest`, `CorrectRemnantRequest`). */
const NOTES_MIN_LENGTH = 10;
const NOTES_MAX_LENGTH = 1000;

/** Una orden abierta que tiene kilos planeados del saldo (todavía no descontados). */
export type RemnantPlannedOrder = {
    order_id: number;
    order_number: string;
    quantity_kg: string;
};

/** Lo que necesitan los diálogos de un saldo; lo envían el listado y el detalle. */
export type AdjustableRemnant = {
    id: number;
    source_order_number: string;
    product_name: string;
    available_quantity_kg: number | string;
    committed_quantity_kg: number | string | null;
    planned_orders: RemnantPlannedOrder[];
};

type RemnantDialogProps = {
    /** El saldo a ajustar; null cierra el diálogo. */
    remnant: AdjustableRemnant | null;
    onClose: () => void;
};

/**
 * Los kilos de hoy y lo planeado en órdenes abiertas: lo que hace falta para decidir. Lo planeado no se puede bajar ni
 * dar de baja (el servidor lo vuelve a revisar con el saldo bloqueado).
 */
function RemnantSummary({ remnant }: { remnant: AdjustableRemnant }) {
    return (
        <dl className="grid gap-3 rounded-md bg-muted/50 p-3 text-sm sm:grid-cols-2">
            <div>
                <dt className="text-xs text-muted-foreground">Disponible</dt>
                <dd className="font-medium tabular-nums">
                    <FormattedNumber
                        value={remnant.available_quantity_kg}
                        kind="quantity"
                    />{' '}
                    kg
                </dd>
            </div>
            <div>
                <dt className="text-xs text-muted-foreground">
                    Planeado en órdenes abiertas
                </dt>
                <dd className="tabular-nums">
                    {remnant.planned_orders.length === 0 ? (
                        'Nada'
                    ) : (
                        <>
                            <FormattedNumber
                                value={remnant.committed_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg
                            <span className="block text-xs text-muted-foreground">
                                {remnant.planned_orders
                                    .map((order) => order.order_number)
                                    .join(', ')}
                            </span>
                        </>
                    )}
                </dd>
            </div>
        </dl>
    );
}

/**
 * El saldo que el diálogo muestra: el abierto o, mientras se cierra, el último que tuvo. Sin esto el contenido se vacía
 * antes de que termine la animación de cierre y se ve un parpadeo.
 */
function useShownRemnant(
    remnant: AdjustableRemnant | null,
): AdjustableRemnant | null {
    const [last, setLast] = useState(remnant);

    if (remnant !== null && remnant !== last) {
        setLast(remnant);
    }

    return remnant ?? last;
}

function remnantLabel(remnant: AdjustableRemnant): string {
    return `${remnant.source_order_number} · ${remnant.product_name}`;
}

/**
 * Da de baja un saldo que ya no sirve: queda en 0 kg, con la nota en su historial. Quien lo muestra decide si se puede
 * (permiso y saldo con kilos).
 */
export function WriteOffRemnantDialog({
    remnant,
    onClose,
}: RemnantDialogProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({ notes: '' });
    const shown = useShownRemnant(remnant);

    useEffect(() => {
        if (remnant) {
            reset();
            clearErrors();
        }
        // Solo al abrir con otro saldo: reset y clearErrors cambian en cada render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [remnant?.id]);

    const submit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (!remnant) {
            return;
        }

        post(remnantWriteOff.url(remnant.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open={remnant !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {shown && (
                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-col gap-4"
                        noValidate
                    >
                        <DialogHeader>
                            <DialogTitle>¿Dar de baja este saldo?</DialogTitle>
                            <DialogDescription>
                                {remnantLabel(shown)}. Queda en 0 kg y ya no se
                                ofrece en las órdenes. El motivo se guarda en su
                                historial.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            <RemnantSummary remnant={shown} />
                            <FormField
                                id="notes"
                                label="Motivo de la baja"
                                required
                                error={errors.notes}
                            >
                                <Textarea
                                    name="notes"
                                    value={data.notes}
                                    maxLength={NOTES_MAX_LENGTH}
                                    placeholder={`Mínimo ${NOTES_MIN_LENGTH} caracteres: se secó, se dañó, se botó…`}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                            </FormField>
                            {/* Lo planeado en una orden abierta, o un saldo que se agotó mientras se escribía. */}
                            <FormError
                                id="remnant"
                                message={
                                    (errors as Partial<Record<string, string>>)
                                        .remnant
                                }
                            />
                        </DialogBody>

                        <DialogFooter>
                            <FormActions
                                submitLabel="Dar de baja"
                                processing={processing}
                                onCancel={onClose}
                                cancelLabel="Volver"
                                destructive
                            />
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Corrige los kilos de un saldo a lo que se pesó, para arriba o para abajo. Se escriben los kilos reales; el sistema
 * guarda la diferencia y recalcula los galones con el peso por galón del saldo.
 */
export function CorrectRemnantDialog({ remnant, onClose }: RemnantDialogProps) {
    const form = useForm({ quantity_kg: '', notes: '' });
    const { data, setData, post, processing, errors, reset, clearErrors } =
        form;
    const shown = useShownRemnant(remnant);

    useEffect(() => {
        if (remnant) {
            reset();
            clearErrors();
        }
        // Solo al abrir con otro saldo: reset y clearErrors cambian en cada render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [remnant?.id]);

    const submit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (!remnant) {
            return;
        }

        form.transform((formData) => ({
            ...formData,
            quantity_kg: toDecimalPayload(formData.quantity_kg),
        }));
        post(remnantCorrect.url(remnant.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open={remnant !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {shown && (
                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-col gap-4"
                        noValidate
                    >
                        <DialogHeader>
                            <DialogTitle>Corregir kilos</DialogTitle>
                            <DialogDescription>
                                {remnantLabel(shown)}. Escribe los kilos que
                                pesaste; los galones se recalculan con su peso
                                por galón y el costo por galón no cambia.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            <RemnantSummary remnant={shown} />
                            <FormField
                                id="quantity_kg"
                                label="Kilos reales"
                                help="Para dejarlo en 0 kg, dalo de baja."
                                required
                                error={errors.quantity_kg}
                            >
                                <DecimalInput
                                    name="quantity_kg"
                                    placeholder="0,00"
                                    {...NUMBER_TYPES.quantity}
                                    value={data.quantity_kg}
                                    onValueChange={(value) =>
                                        setData('quantity_kg', value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="notes"
                                label="Motivo de la corrección"
                                required
                                error={errors.notes}
                            >
                                <Textarea
                                    name="notes"
                                    value={data.notes}
                                    maxLength={NOTES_MAX_LENGTH}
                                    placeholder={`Mínimo ${NOTES_MIN_LENGTH} caracteres: por qué cambian los kilos`}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                            </FormField>
                        </DialogBody>

                        <DialogFooter>
                            <FormActions
                                submitLabel="Guardar corrección"
                                processing={processing}
                                onCancel={onClose}
                                cancelLabel="Volver"
                            />
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
