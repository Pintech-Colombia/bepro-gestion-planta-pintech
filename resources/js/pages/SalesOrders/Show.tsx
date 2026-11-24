import { Head, usePage } from '@inertiajs/react';

import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import { FormError } from '@/components/form-field';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import Heading from '@/components/heading';
import { PageHeader } from '@/components/page-header';
import { PriorityBadge } from '@/components/priority-badge';
import { RecordLink } from '@/components/record-link';
import { ApproveOrderDialog } from '@/components/sales-orders/approve-order-dialog';
import { EditOrderDialog } from '@/components/sales-orders/edit-order-dialog';
import { HoldOrderDialog } from '@/components/sales-orders/hold-order-dialog';
import { StatusBadge } from '@/components/status-badge';
import { StatusTransitions } from '@/components/status-transitions';
import type {
    StatusTransition,
    TransitionCopy,
} from '@/components/status-transitions';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { breadcrumbHref } from '@/lib/navigation';
import { quotationName } from '@/lib/quotation-name';
import { show as quotationsShow } from '@/routes/quotations';
import {
    index as salesOrdersIndex,
    show as salesOrdersShow,
    updateStatus as salesOrdersUpdateStatus,
} from '@/routes/sales-orders';

type ProductItem = {
    id: number;
    product: { id: number; name: string };
    product_variant: {
        id: number;
        presentation_label: string;
    } | null;
    quantity: number;
    color: string | null;
};

type SalesOrderDetail = {
    id: number;
    client: {
        id: number;
        business_name: string;
        nit: string | null;
        contact_name: string | null;
        phone: string | null;
    };
    status: string;
    status_label: string;
    priority: string;
    priority_label: string;
    required_date: string | null;
    estimated_delivery_date: string | null;
    notes: string | null;
    shipping_address: string | null;
    quotation: { id: number; quotation_number: number | null } | null;
    items: ProductItem[];
    created_at: string;
    created_on: string | null;
    creator: { name: string } | null;
    /** La decisión: retenido y/o aprobado. Al aprobar un retenido se conservan los datos de la retención. */
    approval: {
        /** Pedido del que salió este como resto de una aprobación parcial. */
        parent_id: number | null;
        /** Pedido con lo que no se aprobó de este (aprobación parcial); a lo sumo uno. */
        remainder_id: number | null;
        approved_by: string | null;
        approved_at: string | null;
        note: string | null;
        held_by: string | null;
        held_at: string | null;
        hold_reason: string | null;
    };
};

type Props = {
    order: SalesOrderDetail;
    statusTransitions: StatusTransition[];
    priorityOptions: { value: string; label: string }[];
    returnTo?: string | null;
    can: {
        update: boolean;
        updateStatus: boolean;
        /** Aprobar: en «Por aprobar» o «Retenido», con `sales_orders.approve`. */
        approve: boolean;
        /** Aprobar parcial: además, el pedido suma al menos 2 unidades (`SalesOrder::canBeSplit`). */
        approvePartially: boolean;
        /** Retener: solo en «Por aprobar». */
        hold: boolean;
        viewQuotation: boolean;
    };
};

/** Textos de cada cambio de estado del pedido. */
function orderTransitionCopy(value: string): TransitionCopy | undefined {
    switch (value) {
        case 'in_progress':
            return {
                label: 'Marcar «En progreso»',
                title: '¿Pasar el pedido a «En progreso»?',
                description: 'Indica que la planta ya está trabajando en él.',
            };
        case 'ready':
            return {
                label: 'Marcar «Lista»',
                title: '¿Pasar el pedido a «Lista»?',
                description: 'Indica que el pedido está listo para despachar.',
            };
        case 'delivered':
            return {
                label: 'Marcar «Entregada»',
                title: '¿Pasar el pedido a «Entregada»?',
                description: 'El pedido queda cerrado: ya no cambia de estado.',
            };
        case 'cancelled':
            return {
                label: 'Cancelar pedido',
                title: '¿Cancelar el pedido?',
                description: 'El pedido se cancela y no se puede reabrir.',
            };
        default:
            return undefined;
    }
}

export default function SalesOrdersShow({
    order,
    statusTransitions,
    priorityOptions,
    can,
}: Props) {
    const { errors } = usePage().props;
    const showTransitions = can.updateStatus && statusTransitions.length > 0;

    return (
        <>
            <Head title={`Pedido #${order.id}`} />

            <div className="space-y-6 p-6">
                <PageHeader
                    title={`Pedido #${order.id}`}
                    badge={
                        <div className="flex flex-wrap items-center gap-2">
                            <PriorityBadge value={order.priority} />
                            <StatusBadge
                                enum="SalesOrderStatus"
                                value={order.status}
                            />
                        </div>
                    }
                    actions={
                        (can.update ||
                            showTransitions ||
                            can.hold ||
                            can.approve) && (
                            <>
                                {can.update && (
                                    <EditOrderDialog
                                        order={order}
                                        priorityOptions={priorityOptions}
                                    />
                                )}
                                {can.hold && (
                                    <HoldOrderDialog orderId={order.id} />
                                )}
                                {can.approve && (
                                    <ApproveOrderDialog
                                        orderId={order.id}
                                        items={order.items}
                                        canApprovePartially={
                                            can.approvePartially
                                        }
                                    />
                                )}
                                {showTransitions && (
                                    <StatusTransitions
                                        transitions={statusTransitions}
                                        copy={orderTransitionCopy}
                                        url={
                                            salesOrdersUpdateStatus(order.id)
                                                .url
                                        }
                                        itemLabel={`el pedido #${order.id}`}
                                    />
                                )}
                            </>
                        )
                    }
                />

                <FormError id="status" message={errors.status} />

                {/* Lo que la planta y quien aprueba necesitan primero: cuándo y a dónde. */}
                <Card>
                    <CardHeader>
                        <CardTitle>Datos del pedido</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DetailList>
                            <DetailItem
                                label="Fecha requerida"
                                value={
                                    <FormattedDate
                                        value={order.required_date}
                                        format="short"
                                    />
                                }
                            />
                            <DetailItem
                                label="Fecha estimada de entrega"
                                value={
                                    <FormattedDate
                                        value={order.estimated_delivery_date}
                                        format="short"
                                        emptyValue="Sin estimar"
                                    />
                                }
                            />
                            <DetailItem
                                label="Dirección de entrega"
                                value={order.shipping_address}
                                span="full"
                                valueClassName="whitespace-pre-wrap"
                            />
                            <DetailItem
                                label="Cliente"
                                value={order.client.business_name}
                            />
                            <DetailItem label="NIT" value={order.client.nit} />
                            <DetailItem
                                label="Contacto"
                                value={order.client.contact_name}
                            />
                            <DetailItem
                                label="Teléfono"
                                value={order.client.phone}
                            />
                        </DetailList>
                    </CardContent>
                </Card>

                <section className="space-y-4">
                    <Heading title="Productos solicitados" />

                    <DetailTable
                        rows={order.items}
                        rowKey={(item) => item.id}
                        caption="Productos solicitados"
                        columns={[
                            {
                                key: 'product',
                                header: 'Producto',
                                cell: (item) => item.product.name,
                            },
                            {
                                key: 'presentation',
                                header: 'Presentación',
                                cell: (item) =>
                                    item.product_variant?.presentation_label ??
                                    'Base',
                                className: 'text-muted-foreground',
                            },
                            {
                                key: 'color',
                                header: 'Color',
                                cell: (item) => item.color,
                                className: 'text-muted-foreground',
                            },
                            {
                                key: 'quantity',
                                header: 'Cantidad',
                                cell: (item) => (
                                    <FormattedNumber
                                        value={item.quantity}
                                        kind="units"
                                    />
                                ),
                                align: 'right',
                                className: 'font-medium',
                            },
                        ]}
                        empty="No hay productos en este pedido."
                    />
                </section>

                {(order.approval.held_at || order.approval.approved_at) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Aprobación</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DetailList>
                                {order.approval.held_at && (
                                    <>
                                        <DetailItem
                                            label="Retenido por"
                                            value={
                                                <>
                                                    {order.approval.held_by} el{' '}
                                                    <FormattedDate
                                                        value={
                                                            order.approval
                                                                .held_at
                                                        }
                                                        format="datetime"
                                                    />
                                                </>
                                            }
                                        />
                                        <DetailItem
                                            label="Motivo de la retención"
                                            value={order.approval.hold_reason}
                                            valueClassName="whitespace-pre-wrap"
                                        />
                                    </>
                                )}
                                {order.approval.approved_at && (
                                    <>
                                        <DetailItem
                                            label="Aprobado por"
                                            value={
                                                <>
                                                    {order.approval.approved_by}{' '}
                                                    el{' '}
                                                    <FormattedDate
                                                        value={
                                                            order.approval
                                                                .approved_at
                                                        }
                                                        format="datetime"
                                                    />
                                                </>
                                            }
                                        />
                                        <DetailItem
                                            label="Nota de la aprobación"
                                            value={order.approval.note}
                                            valueClassName="whitespace-pre-wrap"
                                        />
                                    </>
                                )}
                                {order.approval.remainder_id !== null && (
                                    <DetailItem
                                        label="Lo no aprobado"
                                        value={
                                            <RecordLink
                                                href={
                                                    salesOrdersShow(
                                                        order.approval
                                                            .remainder_id,
                                                    ).url
                                                }
                                            >
                                                Resto en el pedido #
                                                {order.approval.remainder_id}
                                            </RecordLink>
                                        }
                                    />
                                )}
                                {order.approval.parent_id !== null && (
                                    <DetailItem
                                        label="Pedido de origen"
                                        value={
                                            <RecordLink
                                                href={
                                                    salesOrdersShow(
                                                        order.approval
                                                            .parent_id,
                                                    ).url
                                                }
                                            >
                                                Resto del pedido #
                                                {order.approval.parent_id}
                                            </RecordLink>
                                        }
                                    />
                                )}
                            </DetailList>
                        </CardContent>
                    </Card>
                )}

                {order.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Notas</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                {order.notes}
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
                                label="Creado por"
                                value={order.creator?.name}
                            />
                            <DetailItem
                                label="Fecha de creación"
                                value={
                                    <FormattedDate
                                        value={order.created_at}
                                        format="datetime"
                                    />
                                }
                            />
                            {order.quotation && can.viewQuotation && (
                                <DetailItem
                                    label="Origen"
                                    value={
                                        <RecordLink
                                            href={
                                                quotationsShow(
                                                    order.quotation.id,
                                                ).url
                                            }
                                        >
                                            {quotationName(order.quotation)}
                                        </RecordLink>
                                    }
                                />
                            )}
                        </DetailList>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10); el listado conserva sus filtros con `returnTo`.
SalesOrdersShow.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: 'Pedidos',
            href: breadcrumbHref(props.returnTo, salesOrdersIndex().url),
        },
        {
            title: `Pedido #${props.order.id}`,
            href: salesOrdersShow(props.order.id),
        },
    ],
});
