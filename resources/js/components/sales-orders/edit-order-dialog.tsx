import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { FormActions } from '@/components/form-actions';
import { FormField } from '@/components/form-field';
import { PlantDateInput } from '@/components/plant-date-input';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { update as salesOrderUpdate } from '@/routes/sales-orders';

/** Máximo de la dirección de envío (`Client::SHIPPING_ADDRESS_MAX_LENGTH`). */
const SHIPPING_ADDRESS_MAX_LENGTH = 255;

type EditOrderDialogProps = {
    order: {
        id: number;
        priority: string;
        estimated_delivery_date: string | null;
        shipping_address: string | null;
        notes: string | null;
        /** Día de planta en que se creó: la fecha estimada no puede ser anterior (D-PV3). */
        created_on: string | null;
        client: { contact_name: string | null; phone: string | null };
    };
    priorityOptions: { value: string; label: string }[];
};

/**
 * «Editar datos» del pedido (DESIGN.md §8: formulario corto en un `Dialog`). Solo mientras no arranca («Por aprobar»,
 * «Retenido» o «Pendiente»): quien aprueba corrige la dirección o la prioridad antes de aprobar.
 */
export function EditOrderDialog({
    order,
    priorityOptions,
}: EditOrderDialogProps) {
    const [open, setOpen] = useState(false);

    const savedData = () => ({
        priority: order.priority,
        estimated_delivery_date: order.estimated_delivery_date ?? '',
        shipping_address: order.shipping_address ?? '',
        client_contact_name: order.client.contact_name ?? '',
        client_phone: order.client.phone ?? '',
        notes: order.notes ?? '',
    });

    const { data, setData, patch, processing, errors, clearErrors } =
        useForm(savedData());

    const handleOpenChange = (next: boolean) => {
        if (processing) {
            return;
        }

        // Al abrir se parte de lo que está guardado ahora (no de los valores con que se montó el formulario: tras un
        // guardado, `reset()` volvería a los de antes). Lo escrito y no guardado en una apertura anterior no reaparece.
        if (next) {
            setData(savedData());
            clearErrors();
        }

        setOpen(next);
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        patch(salesOrderUpdate(order.id).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => handleOpenChange(true)}
            >
                Editar datos
            </Button>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent>
                    <form
                        noValidate
                        onSubmit={submit}
                        className="flex min-h-0 flex-col gap-4"
                    >
                        <DialogHeader>
                            <DialogTitle>Editar datos del pedido</DialogTitle>
                            <DialogDescription>
                                Se puede corregir mientras el pedido no arranca
                                en planta.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <FormField
                                    id="priority"
                                    label="Prioridad"
                                    required
                                    error={errors.priority}
                                >
                                    {(control) => (
                                        <Select
                                            value={data.priority}
                                            onValueChange={(value) =>
                                                setData('priority', value)
                                            }
                                        >
                                            <SelectTrigger {...control}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorityOptions.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </FormField>

                                <FormField
                                    id="estimated_delivery_date"
                                    label="Fecha estimada de entrega"
                                    error={errors.estimated_delivery_date}
                                >
                                    <PlantDateInput
                                        clearable
                                        min={order.created_on}
                                        saved={order.estimated_delivery_date}
                                        value={data.estimated_delivery_date}
                                        onChange={(value) =>
                                            setData(
                                                'estimated_delivery_date',
                                                value,
                                            )
                                        }
                                    />
                                </FormField>
                            </div>

                            <FormField
                                id="shipping_address"
                                label="Dirección de entrega"
                                required
                                error={errors.shipping_address}
                                help="Solo cambia la de este pedido; la del cliente no se modifica."
                            >
                                <Textarea
                                    rows={2}
                                    maxLength={SHIPPING_ADDRESS_MAX_LENGTH}
                                    value={data.shipping_address}
                                    onChange={(e) =>
                                        setData(
                                            'shipping_address',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>

                            <div className="grid gap-4 md:grid-cols-2">
                                <FormField
                                    id="client_contact_name"
                                    label="Contacto"
                                    error={errors.client_contact_name}
                                >
                                    <Input
                                        value={data.client_contact_name}
                                        onChange={(e) =>
                                            setData(
                                                'client_contact_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </FormField>

                                <FormField
                                    id="client_phone"
                                    label="Teléfono"
                                    error={errors.client_phone}
                                >
                                    <Input
                                        value={data.client_phone}
                                        onChange={(e) =>
                                            setData(
                                                'client_phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            </div>

                            <FormField
                                id="notes"
                                label="Notas"
                                error={errors.notes}
                            >
                                <Textarea
                                    rows={3}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                            </FormField>
                        </DialogBody>

                        <DialogFooter>
                            <FormActions
                                submitLabel="Guardar cambios"
                                processing={processing}
                                onCancel={() => handleOpenChange(false)}
                            />
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
