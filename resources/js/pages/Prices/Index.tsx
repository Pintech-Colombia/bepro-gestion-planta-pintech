import { Head } from '@inertiajs/react';
import type { ComponentProps } from 'react';

import { DataTableFilters } from '@/components/data-table-filters';
import { PageHeader } from '@/components/page-header';
import Pagination from '@/components/pagination';
import { PriceListProductSection } from '@/components/pricing/price-list-product-section';
import type { PriceListProductRow } from '@/components/pricing/price-list-product-section';
import { useFilters } from '@/hooks/use-filters';
import { index as pricesIndex } from '@/routes/prices';
import type { Paginated } from '@/types/ui';

type Props = {
    products: Paginated<PriceListProductRow>;
    can: {
        view_costs: boolean;
        view_prices: boolean;
        update_margin: boolean;
    };
    filters: {
        search?: string;
    };
};

export default function PricesIndex({
    products: productsData,
    can,
    filters,
}: Props) {
    const {
        filters: filterState,
        setFilter,
        setFilterImmediate,
        clearFilters,
    } = useFilters({
        routeUrl: pricesIndex().url,
        initialFilters: {
            search: filters.search ?? '',
        },
    });

    const filterFields: ComponentProps<typeof DataTableFilters>['fields'] = [
        {
            type: 'text',
            name: 'search',
            label: 'Buscar',
            placeholder: 'Buscar producto o presentación...',
        },
    ];

    return (
        <>
            <Head title="Lista de precios" />

            <div className="space-y-6 p-6">
                <PageHeader
                    title="Lista de precios"
                    description="Precios de venta vigentes por producto y presentación."
                />

                <DataTableFilters
                    fields={filterFields}
                    filters={filterState}
                    onFilter={setFilter}
                    onFilterImmediate={setFilterImmediate}
                    onClear={clearFilters}
                />

                {productsData.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border px-4 py-12 text-center text-sm text-muted-foreground">
                        No hay productos registrados.
                    </div>
                ) : (
                    productsData.data.map((product) => (
                        <PriceListProductSection
                            key={product.id}
                            product={product}
                            can={{
                                view_costs: can.view_costs,
                                update_margin: can.update_margin,
                            }}
                        />
                    ))
                )}

                <Pagination
                    paginator={productsData}
                    noun={{ one: 'producto', other: 'productos' }}
                />
            </div>
        </>
    );
}
