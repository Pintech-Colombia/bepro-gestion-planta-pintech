import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import { DetailItem, DetailList } from '@/components/detail-list';
import { DetailTable } from '@/components/detail-table';
import type { DetailTableColumn } from '@/components/detail-table';
import { FormattedNumber } from '@/components/formatted-number';
import Heading from '@/components/heading';
import { PrintLabelsButton } from '@/components/production/print-labels-button';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { parseDecimal } from '@/lib/numeric-input';
import { cn } from '@/lib/utils';
import type {
    LabelMaterialOption,
    ProductionOrder,
    ProductionOrderPackagingCost,
    ProductionOrderPackagingFormRow,
} from '@/types/production-orders';

type OpPackagingReadProps = {
    order: ProductionOrder;
    rows: ProductionOrderPackagingFormRow[];
    costs?: ReadonlyMap<number, ProductionOrderPackagingCost>;
    labelMaterials: LabelMaterialOption[];
    showCosts: boolean;
    canPrintLabels: boolean;
};

/** Un número guardado distinto a lo envasado (al completar, el servidor guarda la cifra aunque sea la misma). */
function differsFromPackaged(
    value: ProductionOrderPackagingFormRow['new_containers_used'],
    actualUnits: ProductionOrderPackagingFormRow['actual_units'],
): boolean {
    const parsed = parseDecimal(value);

    return parsed !== null && parsed !== (parseDecimal(actualUnits) ?? 0);
}

function hasMaterialDifference(row: ProductionOrderPackagingFormRow): boolean {
    return (
        differsFromPackaged(row.new_containers_used, row.actual_units) ||
        (row.label_raw_material_id !== null &&
            differsFromPackaged(row.labels_used, row.actual_units))
    );
}

/**
 * Envasado de una orden cerrada a la edición: lo envasado por presentación con sus costos y estampitas; envases y
 * etiquetas plegados como en la OP abierta (abiertos si alguna presentación usó una cantidad distinta); y el sobrante,
 * el peso por galón y el derrame.
 */
export function OpPackagingRead({
    order,
    rows,
    costs,
    labelMaterials,
    showCosts,
    canPrintLabels,
}: OpPackagingReadProps) {
    const changedRows = rows.filter(hasMaterialDifference).length;
    const [materialsOpen, setMaterialsOpen] = useState(changedRows > 0);
    const labelCodes = new Map(
        labelMaterials.map((label) => [label.id, label.code]),
    );

    const columns: DetailTableColumn<ProductionOrderPackagingFormRow>[] = [
        {
            key: 'presentation',
            header: 'Presentación',
            cell: (row) => row.presentation,
        },
        {
            key: 'planned',
            header: 'Planeado',
            cell: (row) => (
                <FormattedNumber value={row.planned_units} kind="units" />
            ),
            align: 'right',
            className: 'text-muted-foreground',
        },
        {
            key: 'packaged',
            header: 'Envasado',
            cell: (row) => (
                <FormattedNumber value={row.actual_units} kind="units" />
            ),
            align: 'right',
        },
        {
            key: 'equivalent',
            header: 'Eq. gal',
            cell: (row) => (
                <FormattedNumber
                    value={
                        (parseDecimal(row.actual_units) ?? 0) *
                        (Number(row.presentation_value) || 0)
                    }
                    kind="quantity"
                />
            ),
            align: 'right',
            className: 'text-muted-foreground',
        },
        ...(showCosts
            ? ([
                  {
                      key: 'unit_cost',
                      header: 'Costo unit.',
                      cell: (row) => (
                          <FormattedNumber
                              value={costs?.get(row.id)?.unit_cost}
                              kind="cost"
                          />
                      ),
                      align: 'right',
                      className: 'text-muted-foreground',
                  },
                  {
                      key: 'unit_cost_with_cif',
                      header: 'Costo unit. con CIF',
                      cell: (row) => (
                          <FormattedNumber
                              value={costs?.get(row.id)?.unit_cost_with_cif}
                              kind="cost"
                          />
                      ),
                      align: 'right',
                      className: 'text-muted-foreground',
                  },
                  {
                      key: 'total_cost',
                      header: 'Costo total',
                      cell: (row) => (
                          <FormattedNumber
                              value={costs?.get(row.id)?.total_cost}
                              kind="cost"
                          />
                      ),
                      align: 'right',
                      className: 'font-medium',
                  },
              ] satisfies DetailTableColumn<ProductionOrderPackagingFormRow>[])
            : []),
        ...(canPrintLabels
            ? ([
                  {
                      key: 'labels',
                      header: 'Estampitas',
                      cell: (row) => (
                          <PrintLabelsButton
                              orderId={order.id}
                              planId={row.id}
                          />
                      ),
                      align: 'right',
                  },
              ] satisfies DetailTableColumn<ProductionOrderPackagingFormRow>[])
            : []),
    ];

    const sameAsPackaged = (row: ProductionOrderPackagingFormRow) => (
        <span className="text-muted-foreground">
            = <FormattedNumber value={row.actual_units} kind="units" />
        </span>
    );

    const materialColumns: DetailTableColumn<ProductionOrderPackagingFormRow>[] =
        [
            {
                key: 'presentation',
                header: 'Presentación',
                cell: (row) => row.presentation,
            },
            {
                key: 'package',
                header: 'Envase',
                cell: (row) => row.package_code ?? 'Sin envase',
                className: 'font-mono text-muted-foreground',
            },
            {
                key: 'new_containers',
                header: 'Envases nuevos',
                cell: (row) =>
                    !row.package_code ? null : differsFromPackaged(
                          row.new_containers_used,
                          row.actual_units,
                      ) ? (
                        <FormattedNumber
                            value={row.new_containers_used}
                            kind="units"
                        />
                    ) : (
                        sameAsPackaged(row)
                    ),
                align: 'right',
            },
            {
                key: 'label',
                header: 'Etiqueta',
                cell: (row) =>
                    row.label_raw_material_id !== null
                        ? (labelCodes.get(row.label_raw_material_id) ??
                          `MP #${row.label_raw_material_id}`)
                        : 'Sin etiqueta',
                className: 'font-mono text-muted-foreground',
            },
            {
                key: 'labels_used',
                header: 'Etiquetas usadas',
                cell: (row) =>
                    row.label_raw_material_id ===
                    null ? null : differsFromPackaged(
                          row.labels_used,
                          row.actual_units,
                      ) ? (
                        <FormattedNumber value={row.labels_used} kind="units" />
                    ) : (
                        sameAsPackaged(row)
                    ),
                align: 'right',
            },
        ];

    const remnantKg = parseDecimal(order.remnant_quantity_kg ?? null);
    const remnantGallons = parseDecimal(order.remnant_quantity_gallons ?? null);

    return (
        // Las tablas de detalle no van dentro de una tarjeta (DESIGN.md §9): el título encima y, debajo, el sobrante en
        // su tarjeta.
        <section className="space-y-4">
            <Heading
                title="Envasado, sobrante y derrame"
                description="Unidades envasadas por presentación. Lo que sobró sin envasar queda como saldo para otras órdenes."
            />
            <div className="space-y-4">
                <DetailTable
                    rows={rows}
                    rowKey={(row) => row.id}
                    columns={columns}
                    empty="Esta orden no tiene plan de empaque."
                    caption="Unidades envasadas por presentación"
                />

                {rows.length > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="-ml-2"
                        aria-expanded={materialsOpen}
                        onClick={() => setMaterialsOpen((open) => !open)}
                    >
                        <ChevronDown
                            className={cn(
                                'size-4 transition-transform',
                                materialsOpen && 'rotate-180',
                            )}
                        />
                        Envases y etiquetas distintos a lo envasado
                        {changedRows > 0 && (
                            <span className="text-muted-foreground">
                                ({changedRows} con cambios)
                            </span>
                        )}
                    </Button>
                )}

                {rows.length > 0 && materialsOpen && (
                    <div className="space-y-2">
                        <p className="text-xs text-muted-foreground">
                            «= N»: igual a lo envasado. Los envases reutilizados
                            no se descuentan ni cuestan.
                        </p>
                        <DetailTable
                            rows={rows}
                            rowKey={(row) => row.id}
                            columns={materialColumns}
                            empty="Esta orden no tiene plan de empaque."
                            caption="Envases y etiquetas por presentación"
                        />
                    </div>
                )}
            </div>

            <Card>
                <CardContent>
                    <DetailList columns={3}>
                        <DetailItem
                            label="Sobrante (kg)"
                            value={
                                remnantKg !== null && remnantKg > 0 ? (
                                    <>
                                        <FormattedNumber
                                            value={remnantKg}
                                            kind="quantity"
                                        />
                                        {remnantGallons !== null && (
                                            <span className="block text-xs text-muted-foreground">
                                                ≈{' '}
                                                <FormattedNumber
                                                    value={remnantGallons}
                                                    kind="quantity"
                                                />{' '}
                                                gal
                                            </span>
                                        )}
                                    </>
                                ) : null
                            }
                        />
                        <DetailItem
                            label="Peso por galón (kg/gal)"
                            value={
                                order.density_kg_per_gallon === null ||
                                order.density_kg_per_gallon ===
                                    undefined ? null : (
                                    <FormattedNumber
                                        value={order.density_kg_per_gallon}
                                        kind="quantity"
                                    />
                                )
                            }
                        />
                        <DetailItem
                            label="Derrame (kg)"
                            value={
                                order.spillage_quantity === null ||
                                order.spillage_quantity === undefined ? null : (
                                    <FormattedNumber
                                        value={order.spillage_quantity}
                                        kind="quantity"
                                    />
                                )
                            }
                        />
                        <DetailItem
                            label="Nota del sobrante"
                            value={order.remnant_notes}
                            span="full"
                            valueClassName="whitespace-pre-wrap"
                        />
                    </DetailList>
                </CardContent>
            </Card>
        </section>
    );
}
