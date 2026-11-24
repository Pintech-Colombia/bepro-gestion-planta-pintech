import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useRef, useState } from 'react';

import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import { FormActions } from '@/components/form-actions';
import { FormError, FormField } from '@/components/form-field';
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
import { RecordLink } from '@/components/record-link';
import { RetryableError } from '@/components/retryable-error';
import type { RowAction } from '@/components/row-actions';
import { StatusBadge } from '@/components/status-badge';
import { StatusTransitions } from '@/components/status-transitions';
import type {
    StatusTransition,
    TransitionCopy,
} from '@/components/status-transitions';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { SHIPPING_ADDRESS_MAX_LENGTH } from '@/lib/shipping-address';
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
    /** Estados a los que se puede pasar desde el actual (`QuotationStatus::nextTransitions()`). */
    statusTransitions: StatusTransition[];
    priorityOptions: { value: string; label: string }[];
    returnTo?: string | null;
};

/** Textos de cada cambio de estado de la cotización. El de «Enviado» depende de desde dónde se llega. */
function quotationTransitionCopy(currentStatus: string) {
    return (value: string): TransitionCopy | undefined => {
        switch (value) {
            case 'sent':
                return currentStatus === 'accepted'
                    ? {
                          label: 'Volver a enviada',
                          title: '¿Volver la cotización a enviada?',
                          description:
                              'Úsalo si el cliente se retractó antes de convertirla en pedido.',
                      }
                    : {
                          label: 'Marcar como enviada',
                          title: '¿Marcar la cotización como enviada?',
                          description:
                              'Indica que ya se la mandaste al cliente. Desde ahí deja de poder editarse.',
                      };
            case 'accepted':
                return {
                    label: 'Aceptada por el cliente',
                    title: '¿El cliente aceptó la cotización?',
                    description:
                        'Queda aceptada y lista para convertirla en pedido.',
                    confirmLabel: 'Marcar como aceptada',
                };
            case 'rejected':
                return {
                    label: 'Rechazada',
                    title: '¿El cliente rechazó la cotización?',
                    description:
                        'Queda rechazada. Si hace falta, se puede volver a borrador para corregirla y enviarla otra vez.',
                    confirmLabel: 'Marcar como rechazada',
                };
            case 'draft':
                return {
                    label: 'Volver a borrador',
                    title: '¿Volver la cotización a borrador?',
                    description:
                        'Se puede editar otra vez. Después hay que marcarla de nuevo como enviada.',
                };
            default:
                return undefined;
        }
    };
}

export default function QuotationsShow({
    quotation,
    can,
    salesOrderId,
    statusTransitions,
    priorityOptions,
    returnTo,
}: Props) {
    const { errors } = usePage().props;

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

    const editUrl = returnTo
        ? withReturnTo(quotationsEdit(quotation.id).url, returnTo)
        : quotationsEdit(quotation.id).url;
    const showTransitions = can.updateStatus && statusTransitions.length > 0;
    // En el celular, «Editar» va en el menú «⋯» de los cambios de estado cuando lo hay; si no, queda como botón.
    const editInMobileMenu = can.update && showTransitions;
    const mobileExtraActions: RowAction[] = editInMobileMenu
        ? [{ label: 'Editar', href: editUrl }]
        : [];

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

    const handleConvertToOrder = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        convertForm.post(quotationsConvertToOrder(quotation.id).url, {
            preserveScroll: true,
            onSuccess: () => setConvertDialogOpen(false),
        });
    };

    const variantDetails = (item: QuotationItem) =>
        [item.product_variant.presentation_label, item.type_label, item.color]
            .filter((part): part is string => Boolean(part))
            .join(' · ');

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
                                    className={
                                        editInMobileMenu
                                            ? 'hidden md:inline-flex'
                                            : undefined
                                    }
                                    asChild
                                >
                                    <Link href={editUrl}>Editar</Link>
                                </Button>
                            )}
                            {showTransitions && (
                                <StatusTransitions
                                    transitions={statusTransitions}
                                    copy={quotationTransitionCopy(
                                        quotation.status,
                                    )}
                                    url={
                                        quotationsUpdateStatus(quotation.id).url
                                    }
                                    itemLabel={`la cotización ${quotation.quotation_number ?? quotation.id}`}
                                    mobileExtraActions={mobileExtraActions}
                                />
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
                            <form
                                noValidate
                                onSubmit={handleConvertToOrder}
                                className="flex min-h-0 flex-col gap-4"
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
                                <DialogBody className="space-y-4">
                                    <FormField
                                        id="priority"
                                        label="Prioridad"
                                        required
                                        error={convertForm.errors.priority}
                                    >
                                        {(control) => (
                                            <Select
                                                value={
                                                    convertForm.data.priority
                                                }
                                                onValueChange={(value) =>
                                                    convertForm.setData(
                                                        'priority',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger {...control}>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {priorityOptions.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
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
                                        id="required_date"
                                        label="Fecha requerida"
                                        required
                                        error={convertForm.errors.required_date}
                                    >
                                        <PlantDateInput
                                            notPast
                                            value={
                                                convertForm.data.required_date
                                            }
                                            onChange={(value) =>
                                                convertForm.setData(
                                                    'required_date',
                                                    value,
                                                )
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="shipping_address"
                                        label="Dirección de envío"
                                        required
                                        error={
                                            convertForm.errors.shipping_address
                                        }
                                        help={
                                            quotation.client.shipping_address
                                                ? 'Dirección registrada del cliente. Cámbiala si este pedido va a otro lugar.'
                                                : 'Este cliente no tiene dirección registrada: escribe a dónde va este pedido.'
                                        }
                                    >
                                        <Textarea
                                            rows={2}
                                            maxLength={
                                                SHIPPING_ADDRESS_MAX_LENGTH
                                            }
                                            value={
                                                convertForm.data
                                                    .shipping_address
                                            }
                                            onChange={(e) =>
                                                convertForm.setData(
                                                    'shipping_address',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="notes"
                                        label="Notas"
                                        error={convertForm.errors.notes}
                                    >
                                        <Textarea
                                            rows={3}
                                            value={convertForm.data.notes}
                                            onChange={(e) =>
                                                convertForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                </DialogBody>
                                <DialogFooter>
                                    <FormActions
                                        submitLabel="Convertir en pedido"
                                        processing={convertForm.processing}
                                        onCancel={() =>
                                            handleConvertDialogOpenChange(false)
                                        }
                                    />
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}

                {activePdfShareError && !pdfShareErrorInBar && (
                    <RetryableError
                        message={activePdfShareError.message}
                        onRetry={activePdfShareError.retry}
                    />
                )}

                <FormError id="status" message={errors.status} />

                {/* Lo primero es lo que se cotiza y cuánto suma; los datos del cliente y las condiciones van después. */}
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
                                        {variantDetails(item)}
                                    </div>
                                </>
                            ),
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
                            key: 'description',
                            header: 'Descripción',
                            cell: (item) => item.description,
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
                            <CardTitle>Condiciones comerciales</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DetailList columns={1}>
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
                                    label="Tiempo de entrega"
                                    value={quotation.delivery_time}
                                />
                            </DetailList>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Datos técnicos</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DetailList columns={3}>
                            <DetailItem
                                label="Tecnología"
                                value={quotation.technology}
                            />
                            <DetailItem label="Línea" value={quotation.line} />
                            <DetailItem label="Área" value={quotation.area} />
                            <DetailItem
                                label="Espesor (mils)"
                                value={quotation.thickness_mils}
                            />
                            <DetailItem
                                label="Método de aplicación"
                                value={quotation.application_method}
                            />
                        </DetailList>
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

                <Card>
                    <CardHeader>
                        <CardTitle>Trazabilidad</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DetailList columns={3}>
                            <DetailItem
                                label="Creada por"
                                value={quotation.creator?.name}
                            />
                            <DetailItem
                                label="Fecha de creación"
                                value={
                                    <FormattedDate
                                        value={quotation.created_at}
                                        format="datetime"
                                    />
                                }
                            />
                            {salesOrderId && can.viewSalesOrder && (
                                <DetailItem
                                    label="Pedido generado"
                                    value={
                                        <RecordLink
                                            href={
                                                salesOrdersShow(salesOrderId)
                                                    .url
                                            }
                                        >
                                            Pedido #{salesOrderId}
                                        </RecordLink>
                                    }
                                />
                            )}
                        </DetailList>
                    </CardContent>
                </Card>
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
