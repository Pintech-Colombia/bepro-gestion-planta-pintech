import { Head } from '@inertiajs/react';
import { Ban, Scale } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';

import { DataListing } from '@/components/data-listing';
import type { DataListingColumn } from '@/components/data-listing';
import { DataTableFilters } from '@/components/data-table-filters';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import Pagination from '@/components/pagination';
import {
    CorrectRemnantDialog,
    WriteOffRemnantDialog,
} from '@/components/production/remnant-adjust-dialogs';
import type { RemnantPlannedOrder } from '@/components/production/remnant-adjust-dialogs';
import { StatusBadge } from '@/components/status-badge';
import { useFilters } from '@/hooks/use-filters';
import {
    index as remnantsIndex,
    show as remnantShow,
} from '@/routes/production/remnants';
import type { Paginated } from '@/types/ui';

type RemnantItem = {
    id: number;
    source_order_id: number;
    source_order_number: string;
    product_id: number;
    product_name: string;
    warehouse_id: number;
    warehouse_name: string;
    available_quantity_gallons: number;
    available_quantity_kg: number;
    /** Kilos planeados en órdenes abiertas que todavía no se descontaron; null si no hay. */
    committed_quantity_kg: number | null;
    /** Kilos que volvieron de cuñetes abiertos en otras OP, solo si por ellos hay más de lo que dejó su OP; null si no. */
    opened_units_kg: string | null;
    /** Las órdenes abiertas con kilos planeados del saldo, para los diálogos de baja y corrección. */
    planned_orders: RemnantPlannedOrder[];
    density_kg_per_gallon: number;
    cost_per_gallon: number | null;
    status: string;
    status_label: string;
    created_at: string;
};

type Option = {
    value: string;
    label: string;
};

type Props = {
    remnants: Paginated<RemnantItem>;
    filters: Record<string, string | null | undefined>;
    statusOptions: Option[];
    warehouseOptions: Option[];
    can: {
        viewCosts: boolean;
        /** Dar de baja y corregir kilos (`production_remnants.adjust`). */
        adjust: boolean;
    };
};

export default function RemnantsIndex({
    remnants,
    filters,
    statusOptions,
    warehouseOptions,
    can,
}: Props) {
    const [correctTarget, setCorrectTarget] = useState<RemnantItem | null>(
        null,
    );
    const [writeOffTarget, setWriteOffTarget] = useState<RemnantItem | null>(
        null,
    );

    const {
        filters: filterState,
        setFilter,
        setFilterImmediate,
        clearFilters,
    } = useFilters({
        routeUrl: remnantsIndex().url,
        initialFilters: {
            search: filters.search ?? '',
            status: filters.status ?? '',
            warehouse_id: filters.warehouse_id ?? '',
        },
    });

    const filterFields: ComponentProps<typeof DataTableFilters>['fields'] = [
        {
            type: 'text',
            name: 'search',
            label: 'Buscar',
            placeholder: 'Buscar por producto, color u orden...',
        },
        {
            type: 'select',
            name: 'status',
            label: 'Estado',
            options: statusOptions,
        },
        {
            type: 'select',
            name: 'warehouse_id',
            label: 'Bodega',
            options: warehouseOptions,
        },
    ];

    const columns: DataListingColumn<RemnantItem>[] = [
        {
            key: 'product',
            header: 'Producto',
            cell: (remnant) => remnant.product_name,
            mobile: 'subtitle',
            className: 'font-medium text-foreground',
        },
        {
            key: 'source_order',
            header: 'Orden origen',
            cell: (remnant) => remnant.source_order_number,
            mobile: 'title',
            className: 'font-mono',
            cardClassName: 'font-mono',
        },
        {
            key: 'warehouse',
            header: 'Bodega',
            cell: (remnant) => remnant.warehouse_name,
            mobile: 'meta',
            className: 'text-muted-foreground',
        },
        {
            key: 'density',
            header: 'Peso por galón',
            cell: (remnant) => (
                <>
                    <FormattedNumber
                        value={remnant.density_kg_per_gallon}
                        maxDecimals={4}
                    />{' '}
                    <span className="text-xs text-muted-foreground">
                        kg/gal
                    </span>
                </>
            ),
            // En el celular cede su lugar a «Comprometido», que sirve para decidir si un saldo se puede usar.
            mobile: 'hidden',
            align: 'right',
        },
        ...(can.viewCosts
            ? [
                  {
                      key: 'cost',
                      header: 'Costo/gal',
                      // Sin costo es un dato que falta, no un vacío: se dice (como «Sin precio» en Lista de precios).
                      cell: (remnant: RemnantItem) => (
                          <FormattedNumber
                              value={remnant.cost_per_gallon}
                              kind="cost"
                              emptyValue="Sin costo"
                          />
                      ),
                      mobile: 'meta' as const,
                      align: 'right' as const,
                  },
              ]
            : []),
        {
            key: 'available_kg',
            header: 'Disponible (kg)',
            cell: (remnant) => (
                <>
                    <FormattedNumber
                        value={remnant.available_quantity_kg}
                        kind="quantity"
                    />
                    {/* Más de lo que dejó su OP: no es un error, son cuñetes abiertos que volvieron. */}
                    {remnant.opened_units_kg !== null && (
                        <span className="block text-xs font-normal text-muted-foreground">
                            Incluye{' '}
                            <FormattedNumber
                                value={remnant.opened_units_kg}
                                kind="quantity"
                            />{' '}
                            kg de cuñetes abiertos
                        </span>
                    )}
                </>
            ),
            mobile: 'primary',
            align: 'right',
            className: 'font-medium',
        },
        {
            key: 'committed_kg',
            header: 'Comprometido (kg)',
            // Planeado en órdenes abiertas: se descuenta al completarlas. Sin nada planeado, «—».
            cell: (remnant) =>
                remnant.committed_quantity_kg === null ? null : (
                    <FormattedNumber
                        value={remnant.committed_quantity_kg}
                        kind="quantity"
                    />
                ),
            mobile: 'meta',
            align: 'right',
        },
        {
            key: 'available_gallons',
            header: 'Disponible (gal)',
            cell: (remnant) => (
                <FormattedNumber
                    value={remnant.available_quantity_gallons}
                    maxDecimals={2}
                    trimTrailingZeros
                />
            ),
            mobile: 'meta',
            align: 'right',
            className: 'text-muted-foreground',
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (remnant) => (
                <StatusBadge enum="RemnantStatus" value={remnant.status} />
            ),
            mobile: 'status',
        },
    ];

    return (
        <>
            <Head title="Saldos de producción" />
            <div className="space-y-6 p-6">
                <PageHeader
                    title="Saldos de producción"
                    description="Producto terminado sobrante de órdenes completadas, disponible para reutilizar en nuevas mezclas."
                />

                <DataTableFilters
                    fields={filterFields}
                    filters={filterState}
                    onFilter={setFilter}
                    onFilterImmediate={setFilterImmediate}
                    onClear={clearFilters}
                />

                <DataListing
                    rows={remnants.data}
                    rowKey={(remnant) => remnant.id}
                    // La orden de origen abre el detalle del saldo, con su historial; desde ahí se llega a la OP.
                    rowHref={(remnant) =>
                        remnantShow({ remnant: remnant.id }).url
                    }
                    rowLabel={(remnant) => remnant.source_order_number}
                    columns={columns}
                    actions={(remnant) => [
                        {
                            label: 'Corregir kilos',
                            icon: Scale,
                            onSelect: () => setCorrectTarget(remnant),
                            hidden: !can.adjust,
                        },
                        {
                            label: 'Dar de baja',
                            icon: Ban,
                            destructive: true,
                            onSelect: () => setWriteOffTarget(remnant),
                            hidden: !can.adjust,
                            // Lo mismo que rechaza el servidor, antes de llenar el formulario.
                            disabledReason:
                                remnant.status === 'consumed'
                                    ? 'No disponible: el saldo está agotado'
                                    : remnant.planned_orders.length > 0
                                      ? `No disponible: lo tienen planeado ${remnant.planned_orders.map((order) => order.order_number).join(', ')}`
                                      : undefined,
                        },
                    ]}
                    empty="No hay saldos registrados."
                />

                <Pagination paginator={remnants} />
            </div>

            <CorrectRemnantDialog
                remnant={correctTarget}
                onClose={() => setCorrectTarget(null)}
            />
            <WriteOffRemnantDialog
                remnant={writeOffTarget}
                onClose={() => setWriteOffTarget(null)}
            />
        </>
    );
}
