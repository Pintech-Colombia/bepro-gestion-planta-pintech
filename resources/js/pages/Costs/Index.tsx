import { Head, Link } from '@inertiajs/react';

import { DataTableFilters } from '@/components/data-table-filters';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import { index as adminCostsIndex } from '@/routes/admin/costs';
import { index as pricesIndex } from '@/routes/prices';
import type { Paginated } from '@/types/ui';

type ProductRow = {
    id: number;
    name: string;
    current_cost: string | number | null;
    cif_percentage: string | number | null;
    current_price: string | number | null;
    sales_margin: string | number | null;
    sales_price: number | null;
};

type Props = {
    products: Paginated<ProductRow>;
    can: {
        update_margin: boolean;
        view_prices: boolean;
    };
    filters: {
        search?: string;
    };
};

export default function CostsIndex({
    products: productsData,
    can,
    filters,
}: Props) {
    const {
        filters: filterState,
        setFilter,
        clearFilters,
    } = useFilters({
        routeUrl: adminCostsIndex().url,
        initialFilters: {
            search: filters.search ?? '',
        },
    });

    return (
        <>
            <Head title="Costos" />

            <div className="space-y-4 p-6">
                <PageHeader
                    title="Costos"
                    description="Consulta de costos actuales, CIF, precio interno y margen por producto."
                    actions={
                        can.update_margin && can.view_prices ? (
                            <Button variant="outline" asChild>
                                <Link href={pricesIndex().url}>
                                    Editar en la lista de precios
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <DataTableFilters
                    fields={[
                        {
                            type: 'text',
                            name: 'search',
                            label: 'Buscar',
                            placeholder: 'Buscar por nombre o código...',
                        },
                    ]}
                    filters={filterState}
                    onFilter={setFilter}
                    onClear={clearFilters}
                />

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div className="relative overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border bg-muted/50">
                                <tr>
                                    <th className="p-4 text-left font-medium">
                                        Producto
                                    </th>
                                    <th className="p-4 text-right font-medium">
                                        Costo Actual
                                    </th>
                                    <th className="p-4 text-right font-medium">
                                        CIF %
                                    </th>
                                    <th className="p-4 text-right font-medium">
                                        Precio Interno
                                    </th>
                                    <th className="p-4 text-right font-medium">
                                        Margen Venta %
                                    </th>
                                    <th className="p-4 text-right font-medium">
                                        Precio Venta
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {productsData.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="p-8 text-center text-sm text-muted-foreground"
                                        >
                                            No hay productos registrados.
                                        </td>
                                    </tr>
                                ) : (
                                    productsData.data.map((product) => (
                                        <tr
                                            key={product.id}
                                            className="border-b border-border/50 transition-colors hover:bg-muted/30"
                                        >
                                            <td className="p-4">
                                                <div className="font-medium text-foreground">
                                                    {product.name}
                                                </div>
                                            </td>
                                            <td className="p-4 text-right">
                                                <FormattedNumber
                                                    value={product.current_cost}
                                                    kind="cost"
                                                />
                                            </td>
                                            <td className="p-4 text-right">
                                                <FormattedNumber
                                                    value={
                                                        product.cif_percentage
                                                    }
                                                    kind="percent"
                                                />
                                            </td>
                                            <td className="p-4 text-right">
                                                <FormattedNumber
                                                    value={
                                                        product.current_price
                                                    }
                                                    kind="cost"
                                                />
                                            </td>
                                            <td className="p-4 text-right">
                                                <FormattedNumber
                                                    value={product.sales_margin}
                                                    kind="percent"
                                                />
                                            </td>
                                            <td className="p-4 text-right">
                                                <FormattedNumber
                                                    value={product.sales_price}
                                                    kind="clientPrice"
                                                    emptyValue="Sin precio"
                                                    bold
                                                />
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <Pagination paginator={productsData} />
            </div>
        </>
    );
}
