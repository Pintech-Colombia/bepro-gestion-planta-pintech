import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useRef, useState } from 'react';

import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import { FormError } from '@/components/form-field';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import { MobileActionBar } from '@/components/mobile-action-bar';
import { PageHeader } from '@/components/page-header';
import { PlantDateInput } from '@/components/plant-date-input';
import {
    SharePdfButton,
    useCanSharePdf,
} from '@/components/quotations/share-pdf-button';
import type { SharePdfErrorState } from '@/components/quotations/share-pdf-button';
import { RetryableError } from '@/components/retryable-error';
import { StatusBadge } from '@/components/status-badge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useIsMobile } from '@/hooks/use-mobile';
import { breadcrumbHref, withReturnTo } from '@/lib/navigation';
import { quotationPdfFilename } from '@/lib/pdf-share';
import { quotationName } from '@/lib/quotation-name';
import { cn } from '@/lib/utils';
import {
    convertToOrder as quotationsConvertToOrder,
    edit as quotationsEdit,
    exportPdf as quotationsExportPdf,
    index as quotationsIndex,
    show as quotationsShow,
    updateStatus as quotationsUpdateStatus,
} from '@/routes/quotations';
import { show as salesOrdersShow } from '@/routes/sales-orders';

type QuotationItem = {
    id: number;
    product: { id: number; name: string };
    product_variant: {
        id: number;
        presentation_label: string;
    };
    description: string | null;
    color: string | null;
    type: string | null;
    type_label: string | null;
    quantity: number;
    list_unit_price: number;
    price_adjustment_pct: number;
    unit_price: number;
    subtotal: number;
};

type QuotationDetail = {
    id: number;
    quotation_number: number | null;
    client: {
        business_name: string | null;
        nit: string | null;
        contact_name: string | null;
        phone: string | null;
        shipping_address: string | null;
    };
    technology: string | null;
    line: string | null;
    thickness_mils: string | null;
    application_method: string | null;
    quotation_date: string | null;
    validity_days: number | null;
    validity_days_label: string | null;
    payment_method: string | null;
    payment_method_label: string | null;
    delivery_time: string | null;
    area: string | null;
    notes: string | null;
    subtotal: number;
    iva_percentage: number;
    iva_amount: number;
    total: number;
    status: string;
    status_label: string;
    items: QuotationItem[];
    created_at: string;
    creator: { name: string; email: string } | null;
};

type StatusOption = {
    value: string;
    label: string;
};

type Props = {
    quotation: QuotationDetail;
    can: {
        update: boolean;
        exportPdf: boolean;
        updateStatus: boolean;
        convertToOrder: boolean;
        viewSalesOrder: boolean;
    };
    salesOrderId: number | null;
    statusOptions: StatusOption[];
    priorityOptions: { value: string; label: string }[];
    returnTo?: string | null;
};

export default function QuotationsShow({
    quotation,
    can,
    salesOrderId,
    statusOptions,
    priorityOptions,
    returnTo,
}: Props) {
    const { data, setData, patch, processing, errors } = useForm({
        status: quotation.status,
    });

    const [convertDialogOpen, setConvertDialogOpen] = useState(false);
    // El diálogo se abre desde dos botones (encabezado en escritorio, barra fija en el celular) sin `DialogTrigger`:
    // se recuerda cuál lo abrió para devolverle el foco al cerrarlo.
    const convertOpenerRef = useRef<HTMLButtonElement | null>(null);
    const [pdfShareError, setPdfShareError] = useState<{
        quotationId: number;
        state: SharePdfErrorState;
    } | null>(null);
    const isMobile = useIsMobile();
    const canSharePdf = useCanSharePdf();
    const activePdfShareError =
        pdfShareError?.quotationId === quotation.id
            ? pdfShareError.state
            : null;

    const { plantToday } = usePage().props;
    const convertForm = useForm({
        priority: 'medium',
        required_date: plantToday,
        notes: quotation.notes ?? '',
        shipping_address: quotation.client.shipping_address ?? '',
    });

    const mobilePrimaryAction = can.convertToOrder
        ? 'convert'
        : can.exportPdf
          ? 'share'
          : null;
    // En el celular sin «Convertir», «Compartir PDF» vive en la barra fija: su error se muestra ahí, a la vista, y no
    // bajo el encabezado.
    const pdfShareErrorInBar = isMobile && mobilePrimaryAction === 'share';

    const handlePdfShareError = (state: SharePdfErrorState | null) => {
        setPdfShareError(
            state
                ? {
                      quotationId: quotation.id,
                      state,
                  }
                : null,
        );
    };

    const handleConvertDialogOpenChange = (open: boolean) => {
        if (convertForm.processing) {
            return;
        }

        // Se limpia al abrir y no en un useEffect: useForm devuelve un
        // objeto nuevo en cada render y como dependencia causaba un bucle
        // infinito (pantalla en blanco).
        if (open) {
            convertForm.reset();
            convertForm.clearErrors();
        }

        setConvertDialogOpen(open);
    };

    const handleStatusUpdate = () => {
        patch(quotationsUpdateStatus(quotation.id).url, {
            preserveScroll: true,
        });
    };

    const handleConvertToOrder = () => {
        convertForm.post(quotationsConvertToOrder(quotation.id).url, {
            preserveScroll: true,
            onSuccess: () => setConvertDialogOpen(false),
        });
    };

    return (
        <>
            <Head title={quotationName(quotation)} />

            <div
                className={cn(
                    'space-y-6 p-6',
                    mobilePrimaryAction && 'pb-24 md:pb-6',
                )}
            >
                <PageHeader
                    title={quotationName(quotation)}
                    badge={
                        <StatusBadge
                            enum="QuotationStatus"
                            value={quotation.status}
                        />
                    }
                    actions={
                        <>
                            {salesOrderId && can.viewSalesOrder && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={salesOrdersShow(salesOrderId).url}
                                    >
                                        Pedido #{salesOrderId}
                                    </Link>
                                </Button>
                            )}
                            {can.exportPdf && (
                                <>
                                    {((isMobile &&
                                        mobilePrimaryAction !== 'share') ||
                                        (!isMobile && canSharePdf)) && (
                                        <SharePdfButton
                                            key={quotation.id}
                                            quotationId={quotation.id}
                                            quotationNumber={
                                                quotation.quotation_number
                                            }
                                            variant="outline"
                                            onErrorChange={handlePdfShareError}
                                        />
                                    )}
                                    <Button variant="outline" asChild>
                                        {isMobile ? (
                                            <a
                                                href={
                                                    quotationsExportPdf(
                                                        quotation.id,
                                                        {
                                                            query: {
                                                                download: 1,
                                                            },
                                                        },
                                                    ).url
                                                }
                                                download={quotationPdfFilename(
                                                    quotation,
                                                )}
                                            >
                                                <FileText className="size-4" />
                                                Ver PDF
                                            </a>
                                        ) : (
                                            <a
                                                href={
                                                    quotationsExportPdf(
                                                        quotation.id,
                                                    ).url
                                                }
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <FileText className="size-4" />
                                                Ver PDF
                                            </a>
                                        )}
                                    </Button>
                                </>
                            )}
                            {can.update && (
                                <Button
                                    variant={
                                        isMobile && mobilePrimaryAction !== null
                                            ? 'outline'
                                            : 'default'
                                    }
                                    asChild
                                >
                                    <Link
                                        href={
                                            returnTo
                                                ? withReturnTo(
                                                      quotationsEdit(
                                                          quotation.id,
                                                      ).url,
                                                      returnTo,
                                                  )
                                                : quotationsEdit(quotation.id)
                                                      .url
                                        }
                                    >
                                        Editar
                                    </Link>
                                </Button>
                            )}
                            {can.convertToOrder && (
                                <Button
                                    type="button"
                                    className="hidden md:inline-flex"
                                    onClick={(event) => {
                                        convertOpenerRef.current =
                                            event.currentTarget;
                                        handleConvertDialogOpenChange(true);
                                    }}
                                >
                                    Convertir en pedido
                                </Button>
                            )}
                        </>
                    }
                />

                {can.convertToOrder && (
                    <Dialog
                        open={convertDialogOpen}
                        onOpenChange={handleConvertDialogOpenChange}
                    >
                        <DialogContent
                            onCloseAutoFocus={(event) => {
                                event.preventDefault();
                                convertOpenerRef.current?.focus();
                            }}
                        >
                            <DialogHeader>
                                <DialogTitle>
                                    Convertir cotización en pedido
                                </DialogTitle>
                                <DialogDescription>
                                    Se creará un pedido con los productos de
                                    esta cotización.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-4 py-2">
                                <div className="space-y-2">
                                    <Label htmlFor="priority">Prioridad</Label>
                                    <Select
                                        value={convertForm.data.priority}
                                        onValueChange={(value) =>
                                            convertForm.setData(
                                                'priority',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="priority">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {priorityOptions.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {convertForm.errors.priority && (
                                        <p className="text-sm text-destructive">
                                            {convertForm.errors.priority}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="required_date">
                                        Fecha requerida
                                    </Label>
                                    <PlantDateInput
                                        id="required_date"
                                        notPast
                                        value={convertForm.data.required_date}
                                        onChange={(value) =>
                                            convertForm.setData(
                                                'required_date',
                                                value,
                                            )
                                        }
                                    />
                                    {convertForm.errors.required_date && (
                                        <p className="text-sm text-destructive">
                                            {convertForm.errors.required_date}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="shipping_address">
                                        Dirección de envío *
                                    </Label>
                                    <Textarea
                                        id="shipping_address"
                                        rows={2}
                                        maxLength={255}
                                        value={
                                            convertForm.data.shipping_address
                                        }
                                        onChange={(e) =>
                                            convertForm.setData(
                                                'shipping_address',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {quotation.client.shipping_address
                                            ? 'Dirección registrada del cliente. Cámbiala si este pedido va a otro lugar.'
                                            : 'Este cliente no tiene dirección registrada: escribe a dónde va este pedido.'}
                                    </p>
                                    {convertForm.errors.shipping_address && (
                                        <p className="text-sm text-destructive">
                                            {
                                                convertForm.errors
                                                    .shipping_address
                                            }
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="notes">Notas</Label>
                                    <Textarea
                                        id="notes"
                                        rows={3}
                                        value={convertForm.data.notes}
                                        onChange={(e) =>
                                            convertForm.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {convertForm.errors.notes && (
                                        <p className="text-sm text-destructive">
                                            {convertForm.errors.notes}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConvertDialogOpen(false)}
                                    disabled={convertForm.processing}
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    onClick={handleConvertToOrder}
                                    disabled={convertForm.processing}
                                >
                                    Confirmar conversión
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}

                {activePdfShareError && !pdfShareErrorInBar && (
                    <RetryableError
                        message={activePdfShareError.message}
                        onRetry={activePdfShareError.retry}
                    />
                )}

                {can.updateStatus && statusOptions.length > 0 && (
                    <Card>
                        <CardContent className="space-y-2">
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="space-y-1">
                                    <label className="text-sm font-medium">
                                        Cambiar estado
                                    </label>
                                    <select
                                        value={data.status}
                                        onChange={(e) =>
                                            setData('status', e.target.value)
                                        }
                                        className="h-10 rounded-md border border-input bg-background px-3 text-base md:text-sm"
                                    >
                                        <option value={quotation.status}>
                                            {quotation.status_label}
                                        </option>
                                        {statusOptions.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            disabled={
                                                processing ||
                                                data.status === quotation.status
                                            }
                                        >
                                            Actualizar estado
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                ¿Cambiar estado de la
                                                cotización?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                El estado pasará de{' '}
                                                <span className="font-semibold">
                                                    {quotation.status_label}
                                                </span>{' '}
                                                a{' '}
                                                <span className="font-semibold">
                                                    {
                                                        statusOptions.find(
                                                            (o) =>
                                                                o.value ===
                                                                data.status,
                                                        )?.label
                                                    }
                                                </span>
                                                .
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>
                                                Cancelar
                                            </AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={handleStatusUpdate}
                                            >
                                                Confirmar cambio
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                            <FormError id="status" message={errors.status} />
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Cliente</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DetailList columns={1}>
                                <DetailItem
                                    label="Razón social"
                                    value={quotation.client.business_name}
                                />
                                <DetailItem
                                    label="NIT"
                                    value={quotation.client.nit}
                                />
                                <DetailItem
                                    label="Contacto"
                                    value={quotation.client.contact_name}
                                />
                                <DetailItem
                                    label="Teléfono"
                                    value={quotation.client.phone}
                                />
                            </DetailList>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Condiciones</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DetailList>
                                <DetailItem
                                    label="Fecha de cotización"
                                    value={
                                        <FormattedDate
                                            value={quotation.quotation_date}
                                            format="short"
                                        />
                                    }
                                />
                                <DetailItem
                                    label="Validez"
                                    value={
                                        quotation.validity_days_label ??
                                        (quotation.validity_days
                                            ? `${quotation.validity_days} días`
                                            : null)
                                    }
                                />
                                <DetailItem
                                    label="Forma de pago"
                                    value={
                                        quotation.payment_method_label ??
                                        quotation.payment_method
                                    }
                                />
                                <DetailItem
                                    label="Entrega"
                                    value={quotation.delivery_time}
                                />
                                <DetailItem
                                    label="Tecnología"
                                    value={quotation.technology}
                                />
                                <DetailItem
                                    label="Línea"
                                    value={quotation.line}
                                />
                                <DetailItem
                                    label="Área"
                                    value={quotation.area}
                                />
                                <DetailItem
                                    label="Espesor (mils)"
                                    value={quotation.thickness_mils}
                                />
                                <DetailItem
                                    label="Método de aplicación"
                                    value={quotation.application_method}
                                />
                                <DetailItem
                                    label="Creada"
                                    value={
                                        <>
                                            <FormattedDate
                                                value={quotation.created_at}
                                                format="datetime"
                                            />
                                            {quotation.creator
                                                ? ` por ${quotation.creator.name}`
                                                : ''}
                                        </>
                                    }
                                />
                            </DetailList>
                        </CardContent>
                    </Card>
                </div>

                <DetailTable
                    rows={quotation.items}
                    rowKey={(item) => item.id}
                    caption="Productos cotizados"
                    columns={[
                        {
                            key: 'product',
                            header: 'Producto',
                            cell: (item) => (
                                <>
                                    <div>{item.product.name}</div>
                                    <div className="text-xs font-normal text-muted-foreground">
                                        {
                                            item.product_variant
                                                .presentation_label
                                        }
                                    </div>
                                </>
                            ),
                        },
                        {
                            key: 'type',
                            header: 'Tipo',
                            cell: (item) => item.type_label,
                        },
                        {
                            key: 'color',
                            header: 'Color',
                            cell: (item) => item.color,
                        },
                        {
                            key: 'description',
                            header: 'Descripción',
                            cell: (item) => item.description,
                        },
                        {
                            key: 'quantity',
                            header: 'Cant.',
                            cell: (item) => (
                                <FormattedNumber
                                    value={item.quantity}
                                    kind="units"
                                />
                            ),
                            align: 'right',
                        },
                        {
                            key: 'list_unit_price',
                            header: 'Precio lista',
                            cell: (item) => (
                                <FormattedNumber
                                    value={item.list_unit_price}
                                    kind="clientPrice"
                                />
                            ),
                            align: 'right',
                        },
                        {
                            key: 'price_adjustment_pct',
                            header: 'Ajuste %',
                            cell: (item) => (
                                <FormattedNumber
                                    value={item.price_adjustment_pct}
                                    kind="percent"
                                />
                            ),
                            align: 'right',
                        },
                        {
                            key: 'unit_price',
                            header: 'Precio unit.',
                            cell: (item) => (
                                <FormattedNumber
                                    value={item.unit_price}
                                    kind="clientPrice"
                                />
                            ),
                            align: 'right',
                        },
                        {
                            key: 'subtotal',
                            header: 'Subtotal',
                            cell: (item) => (
                                <FormattedNumber
                                    value={item.subtotal}
                                    kind="clientPrice"
                                />
                            ),
                            align: 'right',
                        },
                    ]}
                    empty="Esta cotización no tiene productos."
                />

                {/* Los totales van aparte y no al pie de la tabla: en el celular la tabla se desplaza y el Total quedaría
                    fuera de la vista. */}
                <Card className="ml-auto max-w-sm">
                    <CardContent className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span>Subtotal</span>
                            <FormattedNumber
                                value={quotation.subtotal}
                                kind="clientPrice"
                            />
                        </div>
                        <div className="flex justify-between">
                            <span>
                                IVA{' '}
                                <FormattedNumber
                                    value={quotation.iva_percentage}
                                    kind="percent"
                                />
                            </span>
                            <FormattedNumber
                                value={quotation.iva_amount}
                                kind="clientPrice"
                            />
                        </div>
                        <div className="flex justify-between border-t border-border pt-2 text-base font-semibold">
                            <span>Total</span>
                            <FormattedNumber
                                value={quotation.total}
                                kind="clientPrice"
                            />
                        </div>
                    </CardContent>
                </Card>

                {quotation.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Observaciones</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                {quotation.notes}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>

            {mobilePrimaryAction && (
                <MobileActionBar
                    notice={
                        pdfShareErrorInBar && activePdfShareError ? (
                            <RetryableError
                                message={activePdfShareError.message}
                                onRetry={activePdfShareError.retry}
                            />
                        ) : undefined
                    }
                >
                    {mobilePrimaryAction === 'convert' ? (
                        <Button
                            type="button"
                            onClick={(event) => {
                                convertOpenerRef.current = event.currentTarget;
                                handleConvertDialogOpenChange(true);
                            }}
                        >
                            Convertir en pedido
                        </Button>
                    ) : (
                        <SharePdfButton
                            key={quotation.id}
                            quotationId={quotation.id}
                            quotationNumber={quotation.quotation_number}
                            variant="default"
                            onErrorChange={handlePdfShareError}
                        />
                    )}
                </MobileActionBar>
            )}
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10); el listado conserva sus filtros con `returnTo`.
QuotationsShow.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: 'Cotizaciones',
            href: breadcrumbHref(props.returnTo, quotationsIndex().url),
        },
        {
            title: quotationName(props.quotation),
            href: quotationsShow(props.quotation.id),
        },
    ],
});
