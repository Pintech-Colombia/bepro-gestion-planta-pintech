import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { FormActions } from '@/components/form-actions';
import { FormCheckbox, FormError, FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
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
import { approve as salesOrdersApprove } from '@/routes/sales-orders';

/** Máximo de la nota: el mismo del servidor (`ApproveSalesOrderRequest`). */
const NOTE_MAX_LENGTH = 2000;

type ApproveOrderDialogProps = {
    orderId: number;
};

/**
 * Aprueba un pedido «Por aprobar» o «Retenido»: pasa a «Pendiente». Quien la muestra decide si se puede
 * (`can.approve`); el servidor vuelve a revisar el estado con el pedido bloqueado. Parcial no cambia las líneas: la
 * nota, obligatoria en ese caso, dice qué se aprueba.
 */
export function ApproveOrderDialog({ orderId }: ApproveOrderDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, clearErrors, reset } =
        useForm({ is_partial: false, note: '' });

    const openDialog = () => {
        reset();
        clearErrors();
        setOpen(true);
    };

    const submit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post(salesOrdersApprove(orderId).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button type="button" onClick={openDialog}>
                Aprobar
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-col gap-4"
                        noValidate
                    >
                        <DialogHeader>
                            <DialogTitle>
                                ¿Aprobar el pedido #{orderId}?
                            </DialogTitle>
                            <DialogDescription>
                                Pasa a «Pendiente» y la planta lo ve como pedido
                                por hacer.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            <FormCheckbox
                                id="is_partial"
                                label="Aprobación parcial"
                                help="Las líneas del pedido no cambian: la nota dice qué se aprueba."
                                checked={data.is_partial}
                                onCheckedChange={(checked) =>
                                    setData('is_partial', checked === true)
                                }
                                error={errors.is_partial}
                            />
                            <FormField
                                id="note"
                                label="Nota"
                                required={data.is_partial}
                                error={errors.note}
                            >
                                <Textarea
                                    name="note"
                                    value={data.note}
                                    maxLength={NOTE_MAX_LENGTH}
                                    onChange={(e) =>
                                        setData('note', e.target.value)
                                    }
                                />
                            </FormField>
                            {/* El pedido cambió de estado mientras se escribía (SalesOrderDecisionLock). */}
                            <FormError
                                id="sales_order"
                                message={
                                    (errors as Partial<Record<string, string>>)
                                        .sales_order
                                }
                            />
                        </DialogBody>

                        <DialogFooter>
                            <FormActions
                                submitLabel="Aprobar pedido"
                                processing={processing}
                                onCancel={() => setOpen(false)}
                            />
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
