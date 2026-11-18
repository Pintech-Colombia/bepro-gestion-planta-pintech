import { Head } from '@inertiajs/react';
import type { ComponentProps } from 'react';

import { CountPopover } from '@/components/count-popover';
import { DataListing } from '@/components/data-listing';
import type { DataListingColumn } from '@/components/data-listing';
import { DataTableFilters } from '@/components/data-table-filters';
import { EnumLabel } from '@/components/enum-label';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import Pagination from '@/components/pagination';
import { RecordLink } from '@/components/record-link';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import { withReturnTo } from '@/lib/navigation';
import { variantPresentationLabel } from '@/lib/variant-label';
import { index as finishedInventoryIndex } from '@/routes/finished-inventory';
import { show as productionOrderShow } from '@/routes/production-orders';
import { show as productsShow } from '@/routes/products';
import type { Paginated } from '@/types/ui';

/** Un lote con stock de la fila, del más antiguo al más nuevo. El costo solo llega con `costs.view`. */
type InventoryLot = {
    id: number;
    lot_number: number;
    entry_date: string;
    quantity: number;
    /** El color de la OP (productos genéricos a pedido); nulo si la OP no lo tiene. */
    color: string | null;
    production_order: { id: number; order_number: string };
    /** Costo de traslado por unidad, con CIF. Nulo: el lote no tiene costo. */
    transfer_unit_cost?: string | null;
    /** Calculado después de completar la OP, con el CIF que tenía el producto al migrar. */
    transfer_cost_estimated?: boolean;
};

type InventoryRow = {
    id: number;
    quantity: number;
    lots: InventoryLot[];
    product: {
        id: number;
        name: string;
        category?: { name: string } | null;
    } | null;
    variant: {
        id: number;
        code: string | null;
        presentation_label: string;
        presentation_value: number | null;
    } | null;
    warehouse: {
        id: number;
        name: string;
        city: string;
        type: 'factory' | 'storage';
    } | null;
};

type Props = {
    inventory: Paginated<InventoryRow>;
    warehouseOptions: { value: string; label: string }[];
    filters: {
        search?: string;
        warehouse_id?: number | string | null;
        product_id?: number | string | null;
        product_name?: string | null;
    };
    can: {
        viewProducts: boolean;
        viewProductionOrders: boolean;
        viewCosts: boolean;
    };
};

function LotList({ lots, can }: { lots: InventoryLot[]; can: Props['can'] }) {
    return (
        <ul className="divide-y divide-border text-sm">
            {lots.map((lot) => (
                <li
                    key={lot.id}
                    className="space-y-0.5 py-2 first:pt-0 last:pb-0"
                >
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="font-medium text-foreground">
                            Lote {lot.lot_number}
                            {lot.color && ` · ${lot.color}`}
                        </span>
                        <span className="font-medium text-foreground tabular-nums">
                            <FormattedNumber
                                value={lot.quantity}
                                kind="units"
                            />{' '}
                            unid.
                        </span>
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {can.viewProductionOrders ? (
                            <RecordLink
                                href={
                                    productionOrderShow(lot.production_order.id)
                                        .url
                                }
                            >
                                {lot.production_order.order_number}
                            </RecordLink>
                        ) : (
                            lot.production_order.order_number
                        )}{' '}
                        · <FormattedDate value={lot.entry_date} />
                    </div>
                    {can.viewCosts && (
                        <div className="text-xs text-muted-foreground">
                            Costo de traslado:{' '}
                            <span className="text-foreground tabular-nums">
                                <FormattedNumber
                                    value={lot.transfer_unit_cost}
                                    kind="unitCost"
                                    emptyValue="Sin costo"
                                />
                            </span>
                            {lot.transfer_cost_estimated && ' (estimado)'}
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function FinishedInventoryIndex({
    inventory,
    warehouseOptions,
    filters,
    can,
}: Props) {
    const {
        filters: filterState,
        setFilter,
        setFilterImmediate,
        clearFilters,
    } = useFilters({
        routeUrl: finishedInventoryIndex().url,
        initialFilters: {
            search: filters.search ?? '',
            warehouse_id: filters.warehouse_id
                ? String(filters.warehouse_id)
                : '',
            product_id: filters.product_id ? String(filters.product_id) : '',
        },
    });

    const filterFields: ComponentProps<typeof DataTableFilters>['fields'] = [
        {
            type: 'text',
            name: 'search',
            label: 'Buscar',
            placeholder: 'Buscar producto, variante o bodega...',
        },
        {
            type: 'select',
            name: 'warehouse_id',
            label: 'Bodega',
            options: warehouseOptions,
        },
    ];

    const clearProductFilter = () => {
        setFilterImmediate('product_id', '');
    };

    const columns: DataListingColumn<InventoryRow>[] = [
        {
            key: 'product',
            header: 'Producto',
            cell: (row) => row.product?.name ?? null,
            mobile: 'title',
        },
        {
            key: 'variant',
            header: 'Presentación',
            cell: (row) =>
                row.variant ? variantPresentationLabel(row.variant) : null,
            mobile: 'subtitle',
            className: 'text-muted-foreground',
        },
        {
            key: 'warehouse',
            header: 'Bodega',
            cell: (row) =>
                row.warehouse ? (
                    <>
                        <div className="text-foreground">
                            {row.warehouse.name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {row.warehouse.city} ·{' '}
                            <EnumLabel
                                enum="WarehouseType"
                                value={row.warehouse.type}
                            />
                        </div>
                    </>
                ) : null,
            mobile: 'meta',
        },
        {
            key: 'lots',
            header: 'Lotes',
            // Los lotes con stock de esta presentación en esta bodega; sus unidades suman la cantidad de la fila.
            cell: (row) =>
                row.lots.length === 0 ? null : (
                    <CountPopover
                        label={
                            row.lots.length === 1
                                ? '1 lote'
                                : `${row.lots.length} lotes`
                        }
                        contentLabel={`Lotes de ${row.product?.name ?? 'producto'}${row.variant ? ` · ${variantPresentationLabel(row.variant)}` : ''}`}
                    >
                        <LotList lots={row.lots} can={can} />
                    </CountPopover>
                ),
            mobile: 'meta',
        },
        {
            key: 'quantity',
            header: 'Cantidad',
            cell: (row) => (
                <FormattedNumber value={row.quantity} kind="units" />
            ),
            mobile: 'primary',
            align: 'right',
            className: 'font-medium text-foreground',
        },
    ];

    return (
        <>
            <Head title="Inventario PT" />

            <div className="space-y-6 p-6">
                <PageHeader
                    title="Inventario PT"
                    description="Disponibilidad de producto terminado por variante y bodega."
                />

                {filterState.product_id ? (
                    <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-4 py-2 text-sm">
                        <span className="text-muted-foreground">
                            Filtrado por producto:{' '}
                            {filters.product_name ??
                                `#${filterState.product_id}`}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={clearProductFilter}
                        >
                            Quitar filtro
                        </Button>
                    </div>
                ) : null}

                <DataTableFilters
                    fields={filterFields}
                    filters={filterState}
                    onFilter={setFilter}
                    onFilterImmediate={setFilterImmediate}
                    onClear={clearFilters}
                />

                <DataListing
                    rows={inventory.data}
                    rowKey={(row) => row.id}
                    // El detalle del producto pide otro permiso: sin él, el nombre queda como texto.
                    rowHref={(row) =>
                        row.product && can.viewProducts
                            ? withReturnTo(productsShow(row.product.id).url)
                            : undefined
                    }
                    columns={columns}
                    empty="No hay stock de producto terminado con los criterios seleccionados."
                />

                <Pagination paginator={inventory} />
            </div>
        </>
    );
}
