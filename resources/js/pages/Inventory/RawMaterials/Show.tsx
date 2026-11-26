import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import RawMaterialController from '@/actions/App/Http/Controllers/Inventory/RawMaterialController';
import { ActiveBadge } from '@/components/active-badge';
import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import type { DetailTableColumn } from '@/components/detail-table';
import { EmptyCell } from '@/components/empty-cell';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import Pagination from '@/components/pagination';
import { SectionTabsList, SectionTabsTrigger } from '@/components/section-tabs';
import { ToneBadge } from '@/components/tone-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { useUrlTab } from '@/hooks/use-url-tab';
import { formatKind } from '@/lib/formatters';
import { breadcrumbHref } from '@/lib/navigation';
import { DeleteRawMaterialDialog } from '@/pages/Inventory/RawMaterials/DeleteRawMaterialDialog';
import type { Paginated } from '@/types/ui';

const BATCHES_TABS = ['existencia', 'historial'] as const;
const BATCHES_CLEAR_PARAMS = ['lotes_page'] as const;
const BATCHES_RELOAD_ONLY = [
    'batchesTab',
    'activeBatches',
    'exhaustedBatches',
    'activeBatchCount',
    'exhaustedBatchCount',
    'totalAvailableQuantity',
    'hasAvailableStock',
];

type BatchesTab = (typeof BATCHES_TABS)[number];

type BatchSummary = {
    id: number;
    lot_number: string | null;
    supplier: string | null;
    initial_quantity: string;
    unit_price?: string;
    entry_date: string;
    expiry_date: string | null;
};

type ActiveBatch = BatchSummary & {
    remaining_quantity: string;
    consumes_first: boolean;
};

type Props = {
    returnTo?: string | null;
    rawMaterial: {
        id: number;
        code: string;
        current_price?: string | null;
        previous_price?: string | null;
        minimum_stock: string;
        alert_days_before_expiry: number;
        tracks_inventory: boolean;
        is_controlled_substance: boolean;
        is_active: boolean;
        unit_of_measure: { id: number; name: string; symbol: string } | null;
        category: {
            id: number;
            name: string;
            family_name: string | null;
            type_label: string;
        } | null;
    };
    batchesTab: BatchesTab;
    activeBatches: ActiveBatch[];
    exhaustedBatches: Paginated<BatchSummary> | null;
    activeBatchCount: number;
    exhaustedBatchCount: number;
    totalAvailableQuantity: string;
    can: {
        update: boolean;
        delete: boolean;
        reactivate: boolean;
        deletePermanently: boolean;
        viewCosts: boolean;
    };
    hasAvailableStock: boolean;
    hasActivity: boolean;
};

/**
 * Raw materials show page displaying detail, batch inventory tabs, and deletion/reactivation controls.
 */
export default function RawMaterialsShow({
    rawMaterial,
    batchesTab,
    activeBatches,
    exhaustedBatches,
    activeBatchCount,
    exhaustedBatchCount,
    totalAvailableQuantity,
    can,
    hasAvailableStock,
    hasActivity,
}: Props) {
    // El servidor decide si «eliminar» borra o desactiva (permiso de borrado y sin historial).
    const deactivates = !can.deletePermanently;

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const {
        tab: activeTab,
        setTab: setActiveTab,
        isLoading: batchesLoading,
    } = useUrlTab({
        param: 'lotes',
        tabs: BATCHES_TABS,
        defaultTab: 'existencia',
        value: batchesTab,
        clearParams: BATCHES_CLEAR_PARAMS,
        reloadOnly: BATCHES_RELOAD_ONLY,
    });

    const handleDeleteConfirm = () => {
        setDeleteProcessing(true);
        router.delete(RawMaterialController.destroy.url(rawMaterial.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleteProcessing(false);
                setDeleteOpen(false);
            },
        });
    };

    const handleReactivate = () => {
        router.patch(RawMaterialController.reactivate.url(rawMaterial.id));
    };

    // Columnas iguales en las dos tablas de lotes; cada tabla agrega su Lote y la de existencia, Disponible.
    const sharedBatchColumns: DetailTableColumn<BatchSummary>[] = [
        {
            key: 'supplier',
            header: 'Proveedor',
            cell: (batch) => batch.supplier,
        },
        {
            key: 'entry',
            header: 'Entrada',
            cell: (batch) => <FormattedDate value={batch.entry_date} />,
            className: 'whitespace-nowrap',
        },
        {
            key: 'expiry',
            header: 'Vence',
            cell: (batch) =>
                batch.expiry_date ? (
                    <FormattedDate value={batch.expiry_date} />
                ) : null,
            className: 'whitespace-nowrap',
        },
        ...(can.viewCosts
            ? [
                  {
                      key: 'price',
                      header: 'Precio unitario',
                      cell: (batch: BatchSummary) => (
                          <FormattedNumber
                              value={batch.unit_price}
                              kind="unitCost"
                          />
                      ),
                      align: 'right' as const,
                  },
              ]
            : []),
        {
            key: 'initial',
            header: 'Cantidad inicial',
            cell: (batch) => (
                <FormattedNumber
                    value={batch.initial_quantity}
                    kind="quantity"
                />
            ),
            align: 'right',
        },
    ];

    const activeBatchColumns: DetailTableColumn<ActiveBatch>[] = [
        {
            key: 'lot',
            header: 'Lote',
            cell: (batch) => {
                if (!batch.lot_number && !batch.consumes_first) {
                    return null;
                }

                return (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono">
                            {batch.lot_number ?? <EmptyCell />}
                        </span>
                        {batch.consumes_first && (
                            <ToneBadge tone="info">
                                Se consume primero
                            </ToneBadge>
                        )}
                    </div>
                );
            },
        },
        ...sharedBatchColumns,
        {
            key: 'remaining',
            header: 'Disponible',
            cell: (batch) => (
                <FormattedNumber
                    value={batch.remaining_quantity}
                    kind="quantity"
                    bold
                    colorize
                />
            ),
            align: 'right',
        },
    ];

    const exhaustedBatchColumns: DetailTableColumn<BatchSummary>[] = [
        {
            key: 'lot',
            header: 'Lote',
            cell: (batch) => batch.lot_number,
            className: 'font-mono',
        },
        ...sharedBatchColumns,
    ];

    const hasAnyBatch = activeBatchCount > 0 || exhaustedBatchCount > 0;
    const showBatchesTabs = rawMaterial.tracks_inventory || hasAnyBatch;
    const loadingHistorial =
        batchesLoading && activeTab === 'historial' && !exhaustedBatches;

    return (
        <>
            <Head title={rawMaterial.code} />

            <div className="space-y-6 p-6">
                <PageHeader
                    title={rawMaterial.code}
                    badge={
                        <ActiveBadge
                            isActive={rawMaterial.is_active}
                            feminine
                        />
                    }
                    description="Detalle de materia prima y lotes asociados."
                    actions={
                        <>
                            {can.update && (
                                <Button asChild>
                                    <Link
                                        href={RawMaterialController.edit.url(
                                            rawMaterial.id,
                                        )}
                                    >
                                        Editar
                                    </Link>
                                </Button>
                            )}

                            {can.delete && !hasAvailableStock && (
                                <Button
                                    variant={
                                        deactivates ? 'warning' : 'destructive'
                                    }
                                    onClick={() => setDeleteOpen(true)}
                                >
                                    {deactivates ? 'Desactivar' : 'Eliminar'}
                                </Button>
                            )}

                            {can.delete && hasAvailableStock && (
                                <Button variant="outline" disabled>
                                    Desactivar (Con stock en bodega)
                                </Button>
                            )}

                            {can.reactivate && (
                                <Button
                                    variant="outline"
                                    onClick={handleReactivate}
                                >
                                    Reactivar
                                </Button>
                            )}
                        </>
                    }
                />

                {/* Información general */}
                <Card>
                    <CardContent>
                        <DetailList columns={2}>
                            <DetailItem
                                label="Código interno"
                                value={rawMaterial.code}
                                valueClassName="font-mono"
                            />

                            <DetailItem
                                label="Familia"
                                value={rawMaterial.category?.family_name}
                            />

                            <DetailItem
                                label="Categoría"
                                value={rawMaterial.category?.name}
                            />

                            <DetailItem
                                label="Tipo de insumo"
                                value={rawMaterial.category?.type_label}
                            />

                            <DetailItem
                                label="Controla inventario"
                                value={
                                    rawMaterial.tracks_inventory
                                        ? 'Sí'
                                        : 'No (consumo sin descontar saldo, a precio fijado a mano)'
                                }
                            />

                            <DetailItem
                                label="Sustancia controlada (SICOQ)"
                                value={
                                    rawMaterial.is_controlled_substance
                                        ? 'Sí'
                                        : 'No'
                                }
                            />

                            <DetailItem
                                label="Unidad"
                                value={
                                    rawMaterial.unit_of_measure &&
                                    `${rawMaterial.unit_of_measure.name} (${rawMaterial.unit_of_measure.symbol})`
                                }
                            />

                            {can.viewCosts && (
                                <>
                                    <DetailItem
                                        label="Precio actual"
                                        value={
                                            <FormattedNumber
                                                value={
                                                    rawMaterial.current_price
                                                }
                                                kind="unitCost"
                                                emptyValue="Sin precio de referencia"
                                            />
                                        }
                                    />

                                    <DetailItem
                                        label="Precio anterior"
                                        value={
                                            <FormattedNumber
                                                value={
                                                    rawMaterial.previous_price
                                                }
                                                kind="unitCost"
                                            />
                                        }
                                    />
                                </>
                            )}

                            <DetailItem
                                label="Stock mínimo"
                                value={
                                    <FormattedNumber
                                        value={rawMaterial.minimum_stock}
                                        kind="quantity"
                                    />
                                }
                            />

                            <DetailItem
                                label="Alerta por vencimiento"
                                value={`${rawMaterial.alert_days_before_expiry} días`}
                            />

                            <DetailItem
                                label="Total disponible (lotes)"
                                value={
                                    <FormattedNumber
                                        value={totalAvailableQuantity}
                                        kind="quantity"
                                    />
                                }
                            />
                        </DetailList>
                    </CardContent>
                </Card>

                {/* Lotes: el total disponible ya está en la ficha de arriba. */}
                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">Lotes asociados</h2>

                    {!rawMaterial.tracks_inventory && (
                        <Alert>
                            <AlertDescription>
                                Esta MP no controla inventario: no tiene lotes.
                            </AlertDescription>
                        </Alert>
                    )}

                    {showBatchesTabs && (
                        <Tabs
                            value={activeTab}
                            onValueChange={setActiveTab}
                            className="space-y-4"
                        >
                            <SectionTabsList>
                                <SectionTabsTrigger value="existencia">
                                    Con existencia (
                                    {formatKind(activeBatchCount, 'units')})
                                </SectionTabsTrigger>
                                <SectionTabsTrigger value="historial">
                                    Historial (
                                    {formatKind(exhaustedBatchCount, 'units')})
                                </SectionTabsTrigger>
                            </SectionTabsList>

                            <TabsContent value="existencia">
                                <DetailTable
                                    rows={activeBatches}
                                    rowKey={(batch) => batch.id}
                                    caption="Lotes con existencia"
                                    columns={activeBatchColumns}
                                    empty="No hay lotes con existencia."
                                />
                            </TabsContent>

                            <TabsContent
                                value="historial"
                                className="space-y-4"
                            >
                                {loadingHistorial ? (
                                    <div
                                        aria-busy="true"
                                        className="space-y-2 rounded-lg border border-border p-4"
                                    >
                                        <Skeleton className="h-4 w-1/3" />
                                        <Skeleton className="h-4 w-full" />
                                        <Skeleton className="h-4 w-5/6" />
                                    </div>
                                ) : (
                                    <>
                                        <DetailTable
                                            rows={exhaustedBatches?.data ?? []}
                                            rowKey={(batch) => batch.id}
                                            caption="Historial de lotes agotados"
                                            columns={exhaustedBatchColumns}
                                            empty="No hay lotes agotados."
                                        />

                                        {exhaustedBatches && (
                                            <Pagination
                                                paginator={exhaustedBatches}
                                                noun={{
                                                    one: 'lote',
                                                    other: 'lotes',
                                                }}
                                            />
                                        )}
                                    </>
                                )}
                            </TabsContent>
                        </Tabs>
                    )}
                </section>
            </div>

            <DeleteRawMaterialDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                rawMaterial={rawMaterial}
                hasActivity={hasActivity}
                deletesPermanently={can.deletePermanently}
                processing={deleteProcessing}
                onConfirm={handleDeleteConfirm}
            />
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10); el listado conserva sus filtros con `returnTo`.
RawMaterialsShow.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: 'Materias primas',
            href: breadcrumbHref(
                props.returnTo,
                RawMaterialController.index.url(),
            ),
        },
        {
            title: props.rawMaterial.code,
            href: RawMaterialController.show.url(props.rawMaterial.id),
        },
    ],
});
