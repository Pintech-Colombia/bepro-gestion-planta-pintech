import { Head, Link, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

import { ActiveBadge } from '@/components/active-badge';
import { DataListing } from '@/components/data-listing';
import type { DataListingColumn } from '@/components/data-listing';
import { DataTableFilters } from '@/components/data-table-filters';
import { FormattedNumber } from '@/components/formatted-number';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@/hooks/use-confirm';
import { useFilters } from '@/hooks/use-filters';
import { formatKind } from '@/lib/formatters';
import {
    create as familiesCreate,
    destroy as familiesDestroy,
    edit as familiesEdit,
    index as familiesIndex,
} from '@/routes/catalogs/raw-material-families';
import type { RawMaterialFamilyRow } from '@/types';
import type { Paginated } from '@/types/ui';

type FamilyRow = RawMaterialFamilyRow & {
    can: {
        update: boolean;
        delete: boolean;
    };
};

type Props = {
    families: Paginated<FamilyRow>;
    filters: Record<string, string | null | undefined>;
    can: {
        create: boolean;
    };
    /** El % general, el de las familias sin el suyo. */
    generalTolerance: string;
};

export default function RawMaterialFamiliesIndex({
    families,
    filters,
    can,
    generalTolerance,
}: Props) {
    const {
        filters: filterState,
        setFilter,
        clearFilters,
    } = useFilters({
        routeUrl: familiesIndex().url,
        initialFilters: {
            search: filters.search ?? '',
            status: filters.status ?? '',
        },
    });

    const confirm = useConfirm();

    const handleDelete = async (family: FamilyRow) => {
        if (
            !(await confirm({
                title: `¿Eliminar la familia «${family.name}»?`,
                confirmLabel: 'Eliminar',
                destructive: true,
            }))
        ) {
            return;
        }

        router.delete(familiesDestroy(family.id).url, {
            preserveScroll: true,
        });
    };

    const columns: DataListingColumn<FamilyRow>[] = [
        {
            key: 'name',
            header: 'Nombre',
            cell: (family) => family.name,
            mobile: 'title',
        },
        {
            key: 'description',
            header: 'Descripción',
            cell: (family) =>
                family.description ? (
                    <span
                        className="block md:max-w-xs md:truncate"
                        title={family.description}
                    >
                        {family.description}
                    </span>
                ) : null,
            mobile: 'subtitle',
            className: 'text-muted-foreground',
        },
        {
            key: 'tolerance',
            header: 'Tolerancia de consumo',
            cell: (family) => (
                <FormattedNumber
                    value={family.consumption_tolerance_percentage}
                    kind="percent"
                    emptyValue={`General (${formatKind(generalTolerance, 'percent')})`}
                />
            ),
            mobile: 'meta',
            align: 'right',
        },
        {
            key: 'categories',
            header: 'Categorías',
            cell: (family) => (
                <FormattedNumber value={family.categories_count} kind="units" />
            ),
            mobile: 'meta',
            align: 'right',
            className: 'text-muted-foreground',
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (family) => (
                <ActiveBadge isActive={family.is_active} feminine />
            ),
            mobile: 'status',
        },
    ];

    return (
        <>
            <Head title="Familias de MP" />

            <div className="space-y-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <Heading
                        title="Familias de MP"
                        description="Agrupan las categorías de materia prima (Aditivo, Pigmento, Resina…)."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={familiesCreate().url}>
                                Nueva familia
                            </Link>
                        </Button>
                    )}
                </div>

                <DataTableFilters
                    fields={[
                        {
                            type: 'text',
                            name: 'search',
                            label: 'Buscar',
                            placeholder: 'Buscar por nombre o descripción...',
                        },
                        {
                            type: 'select',
                            name: 'status',
                            label: 'Estado',
                            options: [
                                { value: 'active', label: 'Activas' },
                                { value: 'inactive', label: 'Inactivas' },
                            ],
                        },
                    ]}
                    filters={filterState}
                    onFilter={setFilter}
                    onClear={clearFilters}
                />

                {/* Sin detalle: el nombre abre la edición. */}
                <DataListing
                    rows={families.data}
                    rowKey={(family) => family.id}
                    rowHref={(family) =>
                        family.can.update
                            ? familiesEdit(family.id).url
                            : undefined
                    }
                    rowLabel={(family) => family.name}
                    columns={columns}
                    // El nombre ya abre la edición: el menú no la repite (DESIGN.md §9).
                    actions={(family) => [
                        {
                            label: 'Eliminar',
                            icon: Trash2,
                            destructive: true,
                            onSelect: () => handleDelete(family),
                            // Con categorías no se puede eliminar (clave foránea RESTRICT): se desactiva.
                            // La salida («desactívala al editarla») solo se ofrece a quien puede editar.
                            disabledReason:
                                family.categories_count > 0
                                    ? family.can.update
                                        ? 'No disponible: tiene categorías. Desactívala al editarla.'
                                        : 'No disponible: tiene categorías.'
                                    : undefined,
                            hidden: !family.can.delete,
                        },
                    ]}
                    empty="No se encontraron familias."
                />

                <Pagination paginator={families} />
            </div>
        </>
    );
}

RawMaterialFamiliesIndex.layout = {
    wide: true,
};
