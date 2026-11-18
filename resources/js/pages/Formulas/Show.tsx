import { Head, Link, router } from '@inertiajs/react';
import { BadgeDollarSign } from 'lucide-react';

import {
    activate as formulasActivate,
    deactivate as formulasDeactivate,
    destroy as formulasDestroy,
    edit as formulasEdit,
    priceReference as formulasPriceReference,
} from '@/actions/App/Http/Controllers/FormulaController';
import { ActiveBadge } from '@/components/active-badge';
import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import { FormattedDate } from '@/components/formatted-date';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import { RecordLink } from '@/components/record-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useConfirm } from '@/hooks/use-confirm';
import { formatNumber } from '@/lib/formatters';
import { formulaCrumb } from '@/lib/formula-name';
import { breadcrumbHref } from '@/lib/navigation';
import {
    index as formulasIndex,
    show as formulasShow,
} from '@/routes/formulas';
import { show as productsShow } from '@/routes/products';

type FormulaLine = {
    id: number;
    step_order: number;
    quantity: string;
    raw_material?: { id: number; code: string } | null;
    /** Un producto como ingrediente (PT consumible), con cómo se costea (P14). */
    product?: { id: number; name: string } | null;
    costing_basis: string | null;
    costing_variant?: { id: number; presentation_label: string } | null;
    unit_of_measure?: { name: string; symbol: string } | null;
};

/** Costo teórico por kilo de una línea de producto (P13): con su desglose, o el motivo por el que no se puede. */
type ProductLineCost = {
    cost_per_kg: string | null;
    bulk_per_kg: string | null;
    packaging_per_kg: string | null;
    density: string | null;
    density_source: 'reference' | 'last_lot' | null;
    density_order_number: string | null;
    reason: string | null;
};

function costingLabel(line: FormulaLine): string {
    if (line.costing_basis === 'bulk') {
        return 'Granel (sin envase)';
    }

    return line.costing_variant?.presentation_label ?? 'Sin elegir';
}

function densityLabel(cost: ProductLineCost): string | null {
    if (cost.density === null) {
        return null;
    }

    const value = `${formatNumber(cost.density, { maxDecimals: 4, trimTrailingZeros: true })} kg/gal`;

    return cost.density_source === 'reference'
        ? `${value} · referencia`
        : `${value} · último lote ${cost.density_order_number ?? ''}`.trim();
}

type Props = {
    returnTo?: string | null;
    formula: {
        id: number;
        version: number;
        name: string | null;
        is_active: boolean;
        is_price_reference: boolean;
        notes: string | null;
        created_at: string;
        product?: {
            id: number;
            name: string;
            unit_of_measure?: { name: string; symbol: string } | null;
        } | null;
        details: FormulaLine[];
        created_by?: { name: string } | null;
        has_production_orders: boolean;
    };
    /** Por id de línea; solo llega con `costs.view`. */
    productLineCosts: Record<number, ProductLineCost>;
    can: {
        update: boolean;
        activate: boolean;
        delete: boolean;
        viewProduct: boolean;
        viewCosts: boolean;
    };
};

export default function FormulasShow({
    formula,
    productLineCosts,
    can,
}: Props) {
    const hasProductLines = formula.details.some(
        (detail) => detail.product != null,
    );
    const confirm = useConfirm();

    const handleDelete = async () => {
        if (
            !(await confirm({
                title: `¿Eliminar la fórmula v${formula.version} de ${formula.product?.name ?? 'fórmula'}?`,
                confirmLabel: 'Eliminar',
                destructive: true,
            }))
        ) {
            return;
        }

        router.delete(formulasDestroy(formula.id));
    };

    const handleActivate = () => {
        router.post(formulasActivate(formula.id));
    };

    const handleDeactivate = () => {
        router.post(formulasDeactivate(formula.id));
    };

    const handleUseForPrice = () => {
        router.post(formulasPriceReference(formula.id));
    };

    // Título, pestaña y última miga dicen lo mismo (DESIGN.md §1 y §10).
    const title = formulaCrumb(formula);

    return (
        <>
            <Head title={title} />
            <div className="space-y-6 p-6">
                <PageHeader
                    title={title}
                    badge={
                        <div className="flex flex-wrap gap-2">
                            <ActiveBadge
                                isActive={formula.is_active}
                                feminine
                            />
                            {formula.is_price_reference && (
                                <Badge variant="outline">
                                    <BadgeDollarSign aria-hidden="true" />
                                    Referencia de precio
                                </Badge>
                            )}
                        </div>
                    }
                    actions={
                        <>
                            {can.update && !formula.has_production_orders && (
                                <Button variant="outline" asChild>
                                    <Link href={formulasEdit(formula.id).url}>
                                        Editar fórmula
                                    </Link>
                                </Button>
                            )}
                            {can.activate &&
                                formula.is_active &&
                                !formula.is_price_reference && (
                                    <Button
                                        variant="outline"
                                        onClick={handleUseForPrice}
                                    >
                                        Usar para el precio
                                    </Button>
                                )}
                            {can.activate && !formula.is_active && (
                                <Button
                                    variant="outline"
                                    onClick={handleActivate}
                                >
                                    Activar
                                </Button>
                            )}
                            {can.activate &&
                                formula.is_active &&
                                !formula.is_price_reference && (
                                    <Button
                                        variant="outline"
                                        onClick={handleDeactivate}
                                    >
                                        Desactivar
                                    </Button>
                                )}
                            {can.delete && !formula.is_price_reference && (
                                <Button
                                    variant="destructive"
                                    onClick={handleDelete}
                                >
                                    Eliminar
                                </Button>
                            )}
                        </>
                    }
                />

                <Card>
                    <CardContent>
                        <DetailList columns={3}>
                            <DetailItem
                                label="Producto"
                                value={
                                    formula.product &&
                                    (can.viewProduct ? (
                                        // Sin `returnTo`: «Productos» en sus migas sigue llevando a Productos.
                                        <RecordLink
                                            href={productsShow(
                                                formula.product.id,
                                            )}
                                        >
                                            {formula.product.name}
                                        </RecordLink>
                                    ) : (
                                        formula.product.name
                                    ))
                                }
                            />
                            {formula.name && (
                                <DetailItem
                                    label="Nombre"
                                    value={formula.name}
                                />
                            )}
                            <DetailItem
                                label="Creada por"
                                value={formula.created_by?.name}
                            />
                            <DetailItem
                                label="Fecha"
                                value={
                                    <FormattedDate
                                        value={formula.created_at}
                                        format="datetime"
                                    />
                                }
                            />
                            <DetailItem
                                label="Notas"
                                value={formula.notes}
                                span="full"
                                valueClassName="whitespace-pre-wrap"
                            />
                        </DetailList>
                    </CardContent>
                </Card>

                {formula.is_price_reference && (
                    <Alert>
                        <AlertDescription>
                            El costo y el precio de lista del producto salen de
                            esta fórmula. Para desactivarla o eliminarla, marca
                            antes otra fórmula activa como referencia de precio
                            con «Usar para el precio».
                        </AlertDescription>
                    </Alert>
                )}

                {formula.has_production_orders && (
                    <Alert variant="warning">
                        <AlertDescription>
                            Esta fórmula ya fue usada en órdenes de producción.
                            Por seguridad del histórico, ya no se puede editar.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Ingredientes */}
                <section className="space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold">Ingredientes</h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Materias primas necesarias para producir una unidad
                            de{' '}
                            <span className="font-medium">
                                {formula.product?.name}
                            </span>
                            {formula.product?.unit_of_measure
                                ? ` (1 ${formula.product.unit_of_measure.symbol})`
                                : ''}
                        </p>
                    </div>

                    <DetailTable
                        rows={formula.details}
                        rowKey={(detail) => detail.id}
                        caption="Ingredientes"
                        columns={[
                            {
                                key: 'step',
                                header: 'Paso',
                                cell: (detail) => (
                                    <FormattedNumber
                                        value={detail.step_order}
                                        kind="units"
                                    />
                                ),
                                align: 'center',
                                className: 'w-14 text-muted-foreground',
                            },
                            {
                                key: 'ingredient',
                                header: 'Ingrediente',
                                cell: (detail) =>
                                    detail.product ? (
                                        <div className="space-y-0.5">
                                            <div className="font-medium">
                                                {can.viewProduct ? (
                                                    <RecordLink
                                                        href={
                                                            productsShow(
                                                                detail.product
                                                                    .id,
                                                            ).url
                                                        }
                                                    >
                                                        {detail.product.name}
                                                    </RecordLink>
                                                ) : (
                                                    detail.product.name
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Se costea como:{' '}
                                                {costingLabel(detail)}
                                            </div>
                                        </div>
                                    ) : (
                                        <span className="font-mono font-medium">
                                            {detail.raw_material?.code}
                                        </span>
                                    ),
                            },
                            {
                                // La unidad va con la cifra («12,5 kg»): sin columna aparte, la tabla cabe a 390 px.
                                key: 'quantity',
                                header: 'Cantidad',
                                cell: (detail) => (
                                    <>
                                        <FormattedNumber
                                            value={detail.quantity}
                                            kind="quantity"
                                        />
                                        {detail.unit_of_measure &&
                                            ` ${detail.unit_of_measure.symbol}`}
                                    </>
                                ),
                                align: 'right',
                            },
                            ...(can.viewCosts && hasProductLines
                                ? [
                                      {
                                          // Solo las líneas de producto: su costo por kilo con el desglose y el peso usado.
                                          key: 'product_cost',
                                          header: 'Costo por kg',
                                          cell: (detail: FormulaLine) => {
                                              const cost =
                                                  productLineCosts[detail.id];

                                              if (!detail.product || !cost) {
                                                  return null;
                                              }

                                              if (cost.cost_per_kg === null) {
                                                  return (
                                                      <span className="text-xs text-destructive-text">
                                                          {cost.reason}
                                                      </span>
                                                  );
                                              }

                                              return (
                                                  <div className="space-y-0.5">
                                                      <FormattedNumber
                                                          value={
                                                              cost.cost_per_kg
                                                          }
                                                          kind="unitCost"
                                                      />
                                                      <div className="text-xs text-muted-foreground">
                                                          Granel{' '}
                                                          <FormattedNumber
                                                              value={
                                                                  cost.bulk_per_kg
                                                              }
                                                              kind="unitCost"
                                                          />{' '}
                                                          + envase y etiqueta{' '}
                                                          <FormattedNumber
                                                              value={
                                                                  cost.packaging_per_kg
                                                              }
                                                              kind="unitCost"
                                                          />
                                                      </div>
                                                      <div className="text-xs text-muted-foreground">
                                                          {densityLabel(cost)}
                                                      </div>
                                                  </div>
                                              );
                                          },
                                          align: 'right' as const,
                                      },
                                  ]
                                : []),
                        ]}
                        empty="Esta fórmula no tiene ingredientes registrados."
                    />
                </section>
            </div>
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10); el listado conserva sus filtros con `returnTo`.
FormulasShow.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: 'Fórmulas',
            href: breadcrumbHref(props.returnTo, formulasIndex().url),
        },
        {
            title: formulaCrumb(props.formula),
            href: formulasShow({ formula: props.formula.id }),
        },
    ],
});
