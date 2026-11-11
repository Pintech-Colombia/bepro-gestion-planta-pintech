import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { FormActions } from '@/components/form-actions';
import { FormCheckbox, FormError, FormField } from '@/components/form-field';
import { FormLineItem } from '@/components/form-line-item';
import { FormattedNumber } from '@/components/formatted-number';
import { IntegerInput } from '@/components/integer-input';
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
import { UNITS_MAX_DIGITS } from '@/lib/number-types';
import { approve as salesOrdersApprove } from '@/routes/sales-orders';

/** Máximo de la nota: el mismo del servidor (`ApproveSalesOrderRequest`). */
const NOTE_MAX_LENGTH = 2000;

/** Una línea del pedido, como llega al detalle (`SalesOrders/Show`). */
export type ApproveOrderItem = {
    id: number;
    product: { name: string };
    product_variant: { presentation_label: string } | null;
    quantity: number;
    color: string | null;
};

type ApproveOrderDialogProps = {
    orderId: number;
    items: ApproveOrderItem[];
    /** Si se puede aprobar parcial (`can.approvePartially`): sin al menos 2 unidades, la casilla no aparece. */
    canApprovePartially: boolean;
};

type ApprovedLine = { id: number; approved_quantity: string };

/** «Aprobar ahora» empieza en lo pedido: se baja lo que no sale. */
const initialLines = (items: ApproveOrderItem[]): ApprovedLine[] =>
    items.map((item) => ({
        id: item.id,
        approved_quantity: String(item.quantity),
    }));

/** Lo que no se aprueba de una línea; un texto que no es entero no cuenta (el servidor lo marca). */
const leftOver = (item: ApproveOrderItem, approved: string): number =>
    /^\d+$/.test(approved) ? Math.max(item.quantity - Number(approved), 0) : 0;

/**
 * Aprueba un pedido «Por aprobar» o «Retenido»: pasa a «Pendiente». Quien la muestra decide si se puede
 * (`can.approve`); el servidor vuelve a revisar el estado con el pedido bloqueado. Parcial dice cuánto sale ahora de
 * cada producto: lo demás pasa a un pedido nuevo «Retenido», con la nota como motivo.
 */
export function ApproveOrderDialog({
    orderId,
    items,
    canApprovePartially,
}: ApproveOrderDialogProps) {
    const [open, setOpen] = useState(false);
    // Las líneas tal como estaban al abrir: las filas y las cantidades salen de la misma copia, así una recarga de la
    // página (por ejemplo, tras un error) nunca pone un producto junto a la cantidad de otro.
    const [lines, setLines] = useState<ApproveOrderItem[]>(items);
    const { data, setData, post, transform, processing, errors, clearErrors } =
        useForm({
            is_partial: false,
            note: '',
            items: initialLines(items),
        });
    const fieldErrors = errors as Partial<Record<string, string>>;

    const openDialog = () => {
        // Con las líneas de ahora: el pedido pudo cambiar desde que se montó la página.
        setLines(items);
        setData({ is_partial: false, note: '', items: initialLines(items) });
        clearErrors();
        setOpen(true);
    };

    const setApproved = (index: number, value: string) =>
        setData(
            'items',
            data.items.map((line, i) =>
                i === index ? { ...line, approved_quantity: value } : line,
            ),
        );

    const remainderCount = lines.filter((item, index) =>
        leftOver(item, data.items[index]?.approved_quantity ?? ''),
    ).length;

    const submit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        // Sin parcial, las cantidades no viajan: se aprueba todo.
        transform((form) =>
            form.is_partial
                ? form
                : { is_partial: false, note: form.note, items: [] },
        );
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
                                {data.is_partial
                                    ? 'Lo que apruebes pasa a «Pendiente» y lo ve la planta; el resto, a un pedido nuevo retenido.'
                                    : 'Pasa a «Pendiente» y la planta lo ve como pedido por hacer.'}
                            </DialogDescription>
                        </DialogHeader>

                        <DialogBody className="space-y-4">
                            {canApprovePartially && (
                                <FormCheckbox
                                    id="is_partial"
                                    label="Aprobación parcial"
                                    help="Indica cuánto sale ahora de cada producto."
                                    checked={data.is_partial}
                                    onCheckedChange={(checked) =>
                                        setData('is_partial', checked === true)
                                    }
                                    error={errors.is_partial}
                                />
                            )}

                            {data.is_partial && (
                                <div className="space-y-3">
                                    {lines.map((item, index) => (
                                        <FormLineItem
                                            key={item.id}
                                            index={index}
                                            label="Producto"
                                            className="md:grid-cols-12 md:items-end"
                                        >
                                            <div className="min-w-0 space-y-1 md:col-span-7">
                                                <p className="text-sm font-medium">
                                                    {item.product.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {[
                                                        item.product_variant
                                                            ?.presentation_label ??
                                                            'Base',
                                                        item.color,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                    {' · Pedido: '}
                                                    <FormattedNumber
                                                        value={item.quantity}
                                                        kind="units"
                                                    />
                                                </p>
                                            </div>
                                            <FormField
                                                id={`items.${index}.approved_quantity`}
                                                label={
                                                    <>
                                                        Aprobar ahora
                                                        <span className="sr-only">
                                                            {' '}
                                                            de{' '}
                                                            {item.product.name}
                                                        </span>
                                                    </>
                                                }
                                                required
                                                error={
                                                    fieldErrors[
                                                        `items.${index}.approved_quantity`
                                                    ] ??
                                                    fieldErrors[
                                                        `items.${index}.id`
                                                    ]
                                                }
                                                className="md:col-span-5"
                                            >
                                                <IntegerInput
                                                    maxDigits={UNITS_MAX_DIGITS}
                                                    placeholder="0"
                                                    value={
                                                        data.items[index]
                                                            ?.approved_quantity ??
                                                        ''
                                                    }
                                                    onValueChange={(value) =>
                                                        setApproved(
                                                            index,
                                                            value,
                                                        )
                                                    }
                                                />
                                            </FormField>
                                        </FormLineItem>
                                    ))}
                                    <FormError
                                        id="items"
                                        message={fieldErrors.items}
                                    />
                                    {remainderCount > 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Pasan a un pedido nuevo retenido:{' '}
                                            {remainderCount === 1
                                                ? '1 producto'
                                                : `${remainderCount} productos`}
                                        </p>
                                    )}
                                </div>
                            )}

                            <FormField
                                id="note"
                                label="Nota"
                                help={
                                    data.is_partial
                                        ? 'Es el motivo del pedido retenido con el resto.'
                                        : undefined
                                }
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
                                message={fieldErrors.sales_order}
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
