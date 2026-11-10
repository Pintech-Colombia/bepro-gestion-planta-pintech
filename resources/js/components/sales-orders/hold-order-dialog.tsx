import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { FormActions } from '@/components/form-actions';
import { FormError, FormField } from '@/components/form-field';
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
import { hold as salesOrdersHold } from '@/routes/sales-orders';

/** Máximo del motivo: el mismo del servidor (`HoldSalesOrderRequest`). */
const REASON_MAX_LENGTH = 2000;

type HoldOrderDialogProps = {
    orderId: number;
};

/**
 * Retiene un pedido «Por aprobar» con un motivo: queda «Retenido» hasta que se apruebe o se cancele, y quien lo creó
 * recibe un aviso. Quien la muestra decide si se puede (`can.hold`); el servidor vuelve a revisar el estado.
 */
export function HoldOrderDialog({ orderId }: HoldOrderDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, clearErrors, reset } =
        useForm({ reason: '' });

    const openDialog = () => {
        reset();
        clearErrors();
        setOpen(true);
    };

    const submit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post(salesOrdersHold(orderId).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button type="button" variant="outline" onClick={openDialog}>
                Retener
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
                                ¿Retener el pedido #{orderId}?
                            </DialogTitle>
                            <DialogDescription>
                                Quien lo creó recibe un aviso con el motivo.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            <FormField
                                id="reason"
                                label="Motivo"
                                help="El pedido queda en espera hasta que lo apruebes."
                                required
                                error={errors.reason}
                            >
                                <Textarea
                                    name="reason"
                                    value={data.reason}
                                    maxLength={REASON_MAX_LENGTH}
                                    onChange={(e) =>
                                        setData('reason', e.target.value)
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
                                submitLabel="Retener pedido"
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
