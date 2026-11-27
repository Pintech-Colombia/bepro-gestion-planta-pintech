import { router } from '@inertiajs/react';
import { ChevronDown, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { destroy as destroyPackagingPlan } from '@/actions/App/Http/Controllers/Production/PackagingPlanController';
import { FormattedNumber } from '@/components/formatted-number';
import { IntegerInput } from '@/components/integer-input';
import { PackagingMaterialsTable } from '@/components/production/packaging-materials-table';
import { PackagingPlanForm } from '@/components/production/packaging-plan-form';
import { PrintLabelsButton } from '@/components/production/print-labels-button';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@/hooks/use-confirm';
import type {
    LabelMaterialOption,
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderPackagingCost,
    ProductionOrderPackagingFormRow,
    ProductionOrderSetData,
    VariantOption,
} from '@/types/production-orders';

type PackagingSectionProps = {
    orderId: number;
    rows: ProductionOrderPackagingFormRow[];
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    availableVariants: VariantOption[];
    labelMaterials: LabelMaterialOption[];
    isReadOnly: boolean;
    showCosts?: boolean;
    /** Costo de cada fila por id del plan, del resumen del servidor (sin y con CIF). */
    costs?: ReadonlyMap<number, ProductionOrderPackagingCost>;
    /** Estampitas de lote: también con la orden en solo lectura (reimprimir una completada). */
    canPrintLabels?: boolean;
    /** Un error en envases o etiquetas abre su bloque plegado, para que el campo quede a la vista. */
    errors?: ProductionOrderErrors;
};

/** Un valor escrito: vacío (o null) significa «tantos como unidades envasadas». */
function isFilled(value: unknown): boolean {
    return value !== '' && value !== null && value !== undefined;
}

/** Una presentación con envases o etiquetas distintos a lo envasado (o con la etiqueta cambiada). */
function hasMaterialChanges(row: ProductionOrderPackagingFormRow): boolean {
    return (
        isFilled(row.new_containers_used) ||
        isFilled(row.labels_used) ||
        row.label_raw_material_id !== row.saved_label_raw_material_id
    );
}

const MATERIAL_ERROR_KEY =
    /^packaging\.\d+\.(new_containers_used|label_raw_material_id|labels_used)$/;

export function PackagingSection({
    orderId,
    rows,
    data,
    setData,
    availableVariants,
    labelMaterials,
    isReadOnly,
    showCosts = true,
    costs,
    canPrintLabels = false,
    errors = {},
}: PackagingSectionProps) {
    const confirm = useConfirm();
    // Envases nuevos y etiquetas casi siempre son lo mismo que lo envasado: van plegados, salvo que alguna fila ya tenga
    // un valor distinto (o una etiqueta cambiada) o un error.
    const changedRows = rows.filter(hasMaterialChanges).length;
    const hasMaterialValues = changedRows > 0;
    const hasMaterialErrors = Object.keys(errors).some((key) =>
        MATERIAL_ERROR_KEY.test(key),
    );
    const [materialsOpen, setMaterialsOpen] = useState(hasMaterialValues);
    const showMaterials = materialsOpen || hasMaterialErrors;
    const showActions = !isReadOnly || canPrintLabels;
    const columnCount = (showCosts ? 7 : 4) + (showActions ? 1 : 0);

    const updateRow = (
        index: number,
        patch: Partial<ProductionOrderPackagingFormRow>,
    ) => {
        const newPackaging = [...data.packaging];
        newPackaging[index] = { ...newPackaging[index], ...patch };
        setData('packaging', newPackaging);
    };

    return (
        <div className="space-y-4">
            <div className="overflow-hidden rounded-md border">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="p-3 text-left">Presentación</th>
                                <th className="p-3 text-right">Planeado</th>
                                <th className="w-32 p-3 text-right">
                                    Envasado
                                </th>
                                <th className="p-3 text-right">Eq. gal</th>
                                {showCosts && (
                                    <>
                                        <th className="p-3 text-right">
                                            Costo unit.
                                        </th>
                                        <th className="p-3 text-right">
                                            Costo unit. con CIF
                                        </th>
                                        <th className="p-3 text-right">
                                            Costo total
                                        </th>
                                    </>
                                )}
                                {showActions && <th className="w-20 p-3"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((pack, index) => (
                                <tr
                                    key={pack.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="p-3 font-medium">
                                        {pack.presentation}
                                    </td>
                                    <td className="p-3 text-right text-muted-foreground">
                                        <FormattedNumber
                                            value={pack.planned_units}
                                            maxDecimals={0}
                                        />
                                    </td>
                                    <td className="p-3">
                                        <IntegerInput
                                            className="h-8 text-right"
                                            value={pack.actual_units}
                                            onValueChange={(value) =>
                                                updateRow(index, {
                                                    actual_units: value,
                                                })
                                            }
                                            disabled={isReadOnly}
                                        />
                                    </td>
                                    <td className="p-3 text-right text-muted-foreground">
                                        <FormattedNumber
                                            value={
                                                (Number(pack.actual_units) ||
                                                    0) *
                                                (Number(
                                                    pack.presentation_value,
                                                ) || 0)
                                            }
                                            maxDecimals={2}
                                        />
                                    </td>
                                    {showCosts && (
                                        <>
                                            {/* La misma cuenta que la tarjeta de costos: nada se calcula aquí. */}
                                            <td className="p-3 text-right text-muted-foreground tabular-nums">
                                                <FormattedNumber
                                                    value={
                                                        costs?.get(pack.id)
                                                            ?.unit_cost
                                                    }
                                                    kind="cost"
                                                />
                                            </td>
                                            <td className="p-3 text-right text-muted-foreground tabular-nums">
                                                <FormattedNumber
                                                    value={
                                                        costs?.get(pack.id)
                                                            ?.unit_cost_with_cif
                                                    }
                                                    kind="cost"
                                                />
                                            </td>
                                            <td className="p-3 text-right font-medium tabular-nums">
                                                <FormattedNumber
                                                    value={
                                                        costs?.get(pack.id)
                                                            ?.total_cost
                                                    }
                                                    kind="cost"
                                                />
                                            </td>
                                        </>
                                    )}
                                    {showActions && (
                                        <td className="p-3">
                                            <div className="flex items-center justify-end gap-2">
                                                {canPrintLabels && (
                                                    <PrintLabelsButton
                                                        orderId={orderId}
                                                        planId={pack.id}
                                                    />
                                                )}
                                                {!isReadOnly && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-7 w-7 text-destructive hover:text-destructive"
                                                        onClick={async () => {
                                                            if (
                                                                await confirm({
                                                                    title: '¿Eliminar esta presentación del plan de envasado?',
                                                                    confirmLabel:
                                                                        'Eliminar',
                                                                    destructive: true,
                                                                })
                                                            ) {
                                                                router.delete(
                                                                    destroyPackagingPlan(
                                                                        {
                                                                            production_order:
                                                                                orderId,
                                                                            plan: pack.id,
                                                                        },
                                                                    ).url,
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td
                                        className="p-3 text-muted-foreground"
                                        colSpan={columnCount}
                                    >
                                        Esta orden no tiene plan de empaque.{' '}
                                        {!isReadOnly &&
                                            'Agrega presentaciones abajo.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {rows.length > 0 && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="-ml-2"
                    aria-expanded={showMaterials}
                    disabled={hasMaterialErrors}
                    onClick={() => setMaterialsOpen((open) => !open)}
                >
                    <ChevronDown
                        className={
                            showMaterials
                                ? 'size-4 rotate-180 transition-transform'
                                : 'size-4 transition-transform'
                        }
                    />
                    Envases y etiquetas distintos a lo envasado
                    {changedRows > 0 && (
                        <span className="text-muted-foreground">
                            ({changedRows} con cambios)
                        </span>
                    )}
                </Button>
            )}

            {rows.length > 0 && showMaterials && (
                <PackagingMaterialsTable
                    rows={rows}
                    labelMaterials={labelMaterials}
                    isReadOnly={isReadOnly}
                    onChange={updateRow}
                />
            )}

            {/* Una presentación va una sola vez por orden: sin presentaciones por agregar, no hay formulario. */}
            {!isReadOnly &&
                (availableVariants.length > 0 ? (
                    <PackagingPlanForm
                        orderId={orderId}
                        availableVariants={availableVariants}
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No quedan presentaciones activas por agregar a esta
                        orden.
                    </p>
                ))}
        </div>
    );
}
