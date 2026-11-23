import { Head } from '@inertiajs/react';
import { Ban, Scale } from 'lucide-react';
import { useState } from 'react';

import { DataListing } from '@/components/data-listing';
import type { DataListingColumn } from '@/components/data-listing';
import { DetailItem, DetailList } from '@/components/detail-list';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import {
    CorrectRemnantDialog,
    WriteOffRemnantDialog,
} from '@/components/production/remnant-adjust-dialogs';
import type { RemnantPlannedOrder } from '@/components/production/remnant-adjust-dialogs';
import { RecordLink } from '@/components/record-link';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatKind, formatNumber } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';
import {
    index as remnantsIndex,
    show as remnantShow,
} from '@/routes/production/remnants';
import { show as productionOrderShow } from '@/routes/production-orders';

type RemnantDetail = {
    id: number;
    source_order_id: number;
    source_order_number: string;
    source_lot_number: number;
    product_name: string;
    warehouse_name: string;
    original_quantity_kg: string;
    original_quantity_gallons: string;
    available_quantity_kg: string;
    available_quantity_gallons: string;
    /** Kilos que recibió de cuñetes abiertos en otras OP; null si ninguno. */
    opened_units_kg: string | null;
    /** Tiene más de lo que dejó su OP al completarse. */
    exceeds_original: boolean;
    /** Kilos planeados en órdenes abiertas que todavía no se descontaron; null si no hay. */
    committed_quantity_kg: string | null;
    planned_orders: RemnantPlannedOrder[];
    density_kg_per_gallon: string;
    /** Solo con `costs.view`. */
    cost_per_gallon: string | null;
    status: string;
    notes: string | null;
    created_by_name: string | null;
    created_at: string | null;
};

type RemnantMovement = {
    id: number;
    type: string;
    /** Hacia dónde movió los kilos: la cantidad llega sin signo. */
    direction: 'in' | 'out';
    quantity_kg: string;
    balance_after_kg: string;
    production_order: { id: number; order_number: string } | null;
    /** Nulo solo en el ajuste que dejó el relleno del historial. */
    user_name: string | null;
    notes: string | null;
    created_at: string | null;
};

type Props = {
    remnant: RemnantDetail;
    movements: RemnantMovement[];
    can: {
        viewCosts: boolean;
        viewProductionOrders: boolean;
        adjust: boolean;
    };
};

export default function RemnantShow({ remnant, movements, can }: Props) {
    const [isCorrecting, setIsCorrecting] = useState(false);
    const [isWritingOff, setIsWritingOff] = useState(false);
    const isExhausted = remnant.status === 'consumed';
    // Dar de baja lo deja en 0 kg: no se ofrece si está agotado ni si una orden abierta lo tiene planeado (la sección
    // «Planeado en órdenes abiertas» dice cuáles). El servidor lo vuelve a revisar con el saldo bloqueado.
    const canWriteOff = !isExhausted && remnant.planned_orders.length === 0;
    const sourceLabel = `${remnant.source_order_number} · Lote ${remnant.source_lot_number}`;
    // Lo original en 0 con cuñetes recibidos: la OP de origen no dejó sobrante y el saldo nació al volver un cuñete.
    const bornFromOpenedUnit =
        remnant.opened_units_kg !== null &&
        (parseDecimal(remnant.original_quantity_kg) ?? 0) === 0;

    const columns: DataListingColumn<RemnantMovement>[] = [
        {
            key: 'created_at',
            header: 'Fecha',
            cell: (movement) => (
                <FormattedDate value={movement.created_at} format="datetime" />
            ),
            mobile: 'title',
            className: 'whitespace-nowrap',
        },
        {
            key: 'type',
            header: 'Tipo',
            cell: (movement) => (
                <StatusBadge enum="RemnantMovementType" value={movement.type} />
            ),
            mobile: 'status',
        },
        {
            key: 'quantity',
            header: 'Kilos',
            cell: (movement) => (
                <span
                    className={
                        movement.direction === 'in'
                            ? 'text-success-text'
                            : 'text-warning-text'
                    }
                >
                    {movement.direction === 'in' ? '+' : '−'}
                    <FormattedNumber
                        value={movement.quantity_kg}
                        kind="quantity"
                    />
                </span>
            ),
            mobile: 'primary',
            align: 'right',
            className: 'font-medium',
        },
        {
            key: 'balance_after',
            header: 'Quedan (kg)',
            cell: (movement) => (
                <FormattedNumber
                    value={movement.balance_after_kg}
                    kind="quantity"
                />
            ),
            mobile: 'meta',
            align: 'right',
        },
        {
            key: 'order',
            header: 'Orden',
            cell: (movement) =>
                movement.production_order ===
                null ? null : can.viewProductionOrders ? (
                    <RecordLink
                        href={
                            productionOrderShow(movement.production_order.id)
                                .url
                        }
                    >
                        {movement.production_order.order_number}
                    </RecordLink>
                ) : (
                    movement.production_order.order_number
                ),
            mobile: 'meta',
            className: 'font-mono',
            cardClassName: 'font-mono',
        },
        {
            key: 'user',
            header: 'Usuario',
            cell: (movement) => movement.user_name,
            mobile: 'meta',
        },
        {
            key: 'notes',
            header: 'Nota',
            // El porqué de una baja o una corrección: en el celular también se lee.
            cell: (movement) => movement.notes,
            mobile: 'meta',
            className: 'min-w-48 whitespace-normal',
            cardClassName: 'whitespace-pre-wrap',
        },
    ];

    return (
        <>
            <Head title={`Saldo de ${remnant.source_order_number}`} />

            <div className="space-y-6 p-6">
                <PageHeader
                    title={`Saldo de ${remnant.source_order_number}`}
                    badge={
                        <StatusBadge
                            enum="RemnantStatus"
                            value={remnant.status}
                        />
                    }
                    description={remnant.product_name}
                    actions={
                        can.adjust && (
                            <>
                                <Button
                                    variant="outline"
                                    className="gap-1.5"
                                    onClick={() => setIsCorrecting(true)}
                                >
                                    <Scale className="h-4 w-4" />
                                    Corregir kilos
                                </Button>
                                {canWriteOff && (
                                    <Button
                                        variant="outline"
                                        className="gap-1.5 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() => setIsWritingOff(true)}
                                    >
                                        <Ban className="h-4 w-4" />
                                        Dar de baja
                                    </Button>
                                )}
                            </>
                        )
                    }
                />

                <Card>
                    <CardContent>
                        <DetailList columns={3}>
                            <DetailItem
                                label="Orden de origen"
                                value={
                                    <span className="font-mono">
                                        {can.viewProductionOrders ? (
                                            <RecordLink
                                                href={
                                                    productionOrderShow(
                                                        remnant.source_order_id,
                                                    ).url
                                                }
                                            >
                                                {sourceLabel}
                                            </RecordLink>
                                        ) : (
                                            sourceLabel
                                        )}
                                    </span>
                                }
                            />
                            <DetailItem
                                label="Bodega"
                                value={remnant.warehouse_name}
                            />
                            <DetailItem
                                label="Nació el"
                                value={
                                    <FormattedDate
                                        value={remnant.created_at}
                                        format="datetime"
                                    />
                                }
                            />
                            <DetailItem
                                label="Disponible"
                                value={
                                    <>
                                        <FormattedNumber
                                            value={
                                                remnant.available_quantity_kg
                                            }
                                            kind="quantity"
                                        />{' '}
                                        kg ·{' '}
                                        <FormattedNumber
                                            value={
                                                remnant.available_quantity_gallons
                                            }
                                            kind="quantity"
                                        />{' '}
                                        gal
                                    </>
                                }
                            />
                            <DetailItem
                                label="Al nacer"
                                value={
                                    <>
                                        <FormattedNumber
                                            value={remnant.original_quantity_kg}
                                            kind="quantity"
                                        />{' '}
                                        kg ·{' '}
                                        <FormattedNumber
                                            value={
                                                remnant.original_quantity_gallons
                                            }
                                            kind="quantity"
                                        />{' '}
                                        gal
                                        {/* Lo disponible pasa lo original por los cuñetes abiertos: no es un error. */}
                                        {remnant.opened_units_kg !== null &&
                                            remnant.exceeds_original && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {bornFromOpenedUnit
                                                        ? 'Su OP no dejó sobrante: el saldo nació de un cuñete abierto en otra orden.'
                                                        : 'Tiene más porque recibió cuñetes abiertos de otras órdenes.'}{' '}
                                                    Recibió{' '}
                                                    <FormattedNumber
                                                        value={
                                                            remnant.opened_units_kg
                                                        }
                                                        kind="quantity"
                                                    />{' '}
                                                    kg (ver historial).
                                                </span>
                                            )}
                                    </>
                                }
                            />
                            <DetailItem
                                label="Planeado en órdenes abiertas"
                                value={
                                    remnant.planned_orders.length === 0 ? (
                                        'Nada'
                                    ) : (
                                        <>
                                            <FormattedNumber
                                                value={
                                                    remnant.committed_quantity_kg
                                                }
                                                kind="quantity"
                                            />{' '}
                                            kg
                                            <span className="block text-xs text-muted-foreground">
                                                {remnant.planned_orders
                                                    .map(
                                                        (order) =>
                                                            `${order.order_number}: ${formatKind(order.quantity_kg, 'quantity')} kg`,
                                                    )
                                                    .join(' · ')}
                                            </span>
                                        </>
                                    )
                                }
                            />
                            <DetailItem
                                label="Peso por galón"
                                // Una medida, con los decimales de su columna: sin tipo de número.
                                value={`${formatNumber(remnant.density_kg_per_gallon, { maxDecimals: 4, trimTrailingZeros: true })} kg/gal`}
                            />
                            {can.viewCosts && (
                                <DetailItem
                                    label="Costo por galón"
                                    value={
                                        <FormattedNumber
                                            value={remnant.cost_per_gallon}
                                            kind="cost"
                                            emptyValue="Sin costo"
                                        />
                                    }
                                />
                            )}
                            <DetailItem
                                label="Registró"
                                value={remnant.created_by_name}
                            />
                            {remnant.notes && (
                                <DetailItem
                                    label="Notas"
                                    value={remnant.notes}
                                    span="full"
                                    valueClassName="whitespace-pre-wrap"
                                />
                            )}
                        </DetailList>
                    </CardContent>
                </Card>

                <section className="space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold">Historial</h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Cada cambio de kilos: cuándo nació, en qué órdenes
                            se usó, lo que volvió de cuñetes abiertos en otras
                            órdenes y sus bajas y correcciones. No se edita: un
                            error se corrige con otro movimiento y una nota.
                        </p>
                    </div>

                    <DataListing
                        rows={movements}
                        rowKey={(movement) => movement.id}
                        columns={columns}
                        empty="Este saldo no tiene movimientos."
                    />
                </section>
            </div>

            <CorrectRemnantDialog
                remnant={isCorrecting ? remnant : null}
                onClose={() => setIsCorrecting(false)}
            />
            <WriteOffRemnantDialog
                remnant={isWritingOff ? remnant : null}
                onClose={() => setIsWritingOff(false)}
            />
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10).
RemnantShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Saldos de producción', href: remnantsIndex() },
        {
            title: `Saldo de ${props.remnant.source_order_number}`,
            href: remnantShow(props.remnant.id),
        },
    ],
});
