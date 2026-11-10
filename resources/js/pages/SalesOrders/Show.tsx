import { Head, Link } from '@inertiajs/react';
import { FileText, ShoppingCart } from 'lucide-react';

import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import { EmptyCell } from '@/components/empty-cell';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import { PriorityBadge } from '@/components/priority-badge';
import { RecordLink } from '@/components/record-link';
import AdminOrderSidebar from '@/components/sales-orders/AdminOrderSidebar';
import { ApproveOrderDialog } from '@/components/sales-orders/approve-order-dialog';
import { HoldOrderDialog } from '@/components/sales-orders/hold-order-dialog';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { quotationName } from '@/lib/quotation-name';
import { show as quotationsShow } from '@/routes/quotations';
import {
    index as salesOrdersIndex,
    show as salesOrdersShow,
} from '@/routes/sales-orders';

type ProductItem = {
    id: number;
    product: { id: number; name: string };
    product_variant: {
        id: number;
        name: string;
        presentation_label: string | null;
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
        /** Pedidos con lo que no se aprobó de este (aprobación parcial). */
        remainder_ids: number[];
        approved_by: string | null;
        approved_at: string | null;
        note: string | null;
        held_by: string | null;
        held_at: string | null;
        hold_reason: string | null;
    };
};

type StatusTransition = {
    value: string;
    label: string;
};

type Props = {
    order: SalesOrderDetail;
    statusTransitions: StatusTransition[];
    can: {
        update: boolean;
        updateStatus: boolean;
        /** Aprobar: en «Por aprobar» o «Retenido», con `sales_orders.approve`. */
        approve: boolean;
        /** Retener: solo en «Por aprobar». */
        hold: boolean;
        viewQuotation: boolean;
    };
};

export default function SalesOrdersShow({
    order,
    statusTransitions,
    can,
}: Props) {
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
                        (can.approve || can.hold) && (
                            <div className="flex flex-wrap items-center gap-2">
                                {can.hold && (
                                    <HoldOrderDialog orderId={order.id} />
                                )}
                                {can.approve && (
                                    <ApproveOrderDialog
                                        orderId={order.id}
                                        items={order.items}
                                    />
                                )}
                            </div>
                        )
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* min-w-0: sin él, la columna de la grilla se ensancha con la tabla y esta no se desplaza. */}
                    <div className="min-w-0 space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Información del cliente</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <p>
                                    <span className="text-muted-foreground">
                                        Razón social:
                                    </span>{' '}
                                    <span className="font-medium">
                                        {order.client.business_name}
                                    </span>
                                </p>
                                {order.client.nit && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            NIT:
                                        </span>{' '}
                                        {order.client.nit}
                                    </p>
                                )}
                                <p>
                                    <span className="text-muted-foreground">
                                        Contacto:
                                    </span>{' '}
                                    {order.client.contact_name ?? <EmptyCell />}
                                </p>
                                <p>
                                    <span className="text-muted-foreground">
                                        Teléfono:
                                    </span>{' '}
                                    {order.client.phone ?? <EmptyCell />}
                                </p>
                                {order.estimated_delivery_date && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            Fecha estimada de entrega:
                                        </span>{' '}
                                        <span className="font-medium">
                                            <FormattedDate
                                                value={
                                                    order.estimated_delivery_date
                                                }
                                                format="date"
                                            />
                                        </span>
                                    </p>
                                )}
                                {order.quotation && can.viewQuotation && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            Origen:
                                        </span>{' '}
                                        <Button
                                            variant="link"
                                            size="sm"
                                            asChild
                                            className="h-auto p-0"
                                        >
                                            <Link
                                                href={
                                                    quotationsShow(
                                                        order.quotation.id,
                                                    ).url
                                                }
                                            >
                                                <FileText className="mr-1 h-3 w-3" />
                                                {quotationName(order.quotation)}
                                            </Link>
                                        </Button>
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {(order.approval.held_at ||
                            order.approval.approved_at) && (
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
                                                            {
                                                                order.approval
                                                                    .held_by
                                                            }{' '}
                                                            el{' '}
                                                            <FormattedDate
                                                                value={
                                                                    order
                                                                        .approval
                                                                        .held_at
                                                                }
                                                                format="datetime"
                                                            />
                                                        </>
                                                    }
                                                />
                                                <DetailItem
                                                    label="Motivo de la retención"
                                                    value={
                                                        order.approval
                                                            .hold_reason
                                                    }
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
                                                            {
                                                                order.approval
                                                                    .approved_by
                                                            }{' '}
                                                            el{' '}
                                                            <FormattedDate
                                                                value={
                                                                    order
                                                                        .approval
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
                                        {order.approval.remainder_ids.length >
                                            0 && (
                                            <DetailItem
                                                label="Lo no aprobado"
                                                value={
                                                    <span className="flex flex-col gap-1">
                                                        {order.approval.remainder_ids.map(
                                                            (id) => (
                                                                <RecordLink
                                                                    key={id}
                                                                    href={
                                                                        salesOrdersShow(
                                                                            id,
                                                                        ).url
                                                                    }
                                                                >
                                                                    Resto en el
                                                                    pedido #{id}
                                                                </RecordLink>
                                                            ),
                                                        )}
                                                    </span>
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
                                                        {
                                                            order.approval
                                                                .parent_id
                                                        }
                                                    </RecordLink>
                                                }
                                            />
                                        )}
                                    </DetailList>
                                </CardContent>
                            </Card>
                        )}

                        <section className="space-y-4">
                            <h2 className="flex items-center gap-2 text-lg font-semibold">
                                <ShoppingCart className="h-5 w-5" />
                                Productos solicitados
                            </h2>

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
                                            item.product_variant
                                                ?.presentation_label ??
                                            item.product_variant?.name ??
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
                    </div>

                    {(can.update || can.updateStatus) && (
                        <AdminOrderSidebar
                            order={order}
                            statusTransitions={statusTransitions}
                            can={can}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10).
SalesOrdersShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Pedidos', href: salesOrdersIndex() },
        {
            title: `Pedido #${props.order.id}`,
            href: salesOrdersShow(props.order.id),
        },
    ],
});
