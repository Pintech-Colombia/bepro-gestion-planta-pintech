import { router } from '@inertiajs/react';
import { ChevronDown, Trash2 } from 'lucide-react';
import { useState } from 'react';

import {
    destroy as destroyPackagingPlan,
    store as storePackagingPlan,
} from '@/actions/App/Http/Controllers/Production/PackagingPlanController';
import { FormattedNumber } from '@/components/formatted-number';
import { IntegerInput } from '@/components/integer-input';
import { PresentationSelect } from '@/components/presentation-select';
import { PrintLabelsButton } from '@/components/production/print-labels-button';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useConfirm } from '@/hooks/use-confirm';
import { variantPresentationLabel } from '@/lib/variant-label';
import type {
    LabelMaterialOption,
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderPackagingCost,
    ProductionOrderPackagingFormRow,
    ProductionOrderSetData,
    VariantOption,
} from '@/types/production-orders';

/** Valor del selector para «sin etiqueta»: un SelectItem no admite valor vacío. */
const NO_LABEL = 'none';

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

/**
 * Envase y etiqueta de cada presentación (3.7), en una tabla aparte de lo envasado: una fila por presentación y la
 * ayuda una sola vez. Vacío = tantos como unidades envasadas (el gris lo muestra); los envases reutilizados no se
 * descuentan ni cuestan. Sin tope: la merma (envase dañado, etiqueta mal pegada) es costo del lote. El `id` de cada
 * campo es la llave de su error, para que el foco llegue a él.
 */
function PackagingMaterialsTable({
    rows,
    labelMaterials,
    isReadOnly,
    onChange,
}: {
    rows: ProductionOrderPackagingFormRow[];
    labelMaterials: LabelMaterialOption[];
    isReadOnly: boolean;
    onChange: (
        index: number,
        patch: Partial<ProductionOrderPackagingFormRow>,
    ) => void;
}) {
    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">
                Vacío = igual a lo envasado. Los envases reutilizados no se
                descuentan ni cuestan.
            </p>
            <div className="overflow-hidden rounded-md border">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="p-3 text-left">Presentación</th>
                                <th className="p-3 text-left">Envase</th>
                                <th className="w-36 p-3 text-right">
                                    Envases nuevos
                                </th>
                                <th className="w-48 p-3 text-left">Etiqueta</th>
                                <th className="w-36 p-3 text-right">
                                    Etiquetas usadas
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((pack, index) => {
                                // Una etiqueta inactiva solo se ofrece al plan que la tiene guardada, aunque el operario
                                // elija otra y quiera volver.
                                const labelOptions = labelMaterials.filter(
                                    (label) =>
                                        label.is_active ||
                                        label.id ===
                                            pack.saved_label_raw_material_id ||
                                        label.id === pack.label_raw_material_id,
                                );
                                const unitsPlaceholder = `= ${pack.actual_units || 0}`;

                                return (
                                    <tr
                                        key={pack.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 font-medium">
                                            {pack.presentation}
                                        </td>
                                        <td className="p-3 font-mono text-muted-foreground">
                                            {pack.package_code ?? 'Sin envase'}
                                        </td>
                                        <td className="p-3">
                                            <IntegerInput
                                                id={`packaging.${index}.new_containers_used`}
                                                aria-label={`Envases nuevos de ${pack.presentation}`}
                                                className="h-8 text-right"
                                                value={pack.new_containers_used}
                                                placeholder={unitsPlaceholder}
                                                onValueChange={(value) =>
                                                    onChange(index, {
                                                        new_containers_used:
                                                            value,
                                                    })
                                                }
                                                disabled={
                                                    isReadOnly ||
                                                    !pack.package_code
                                                }
                                            />
                                        </td>
                                        <td className="p-3">
                                            <Select
                                                value={
                                                    pack.label_raw_material_id !=
                                                    null
                                                        ? String(
                                                              pack.label_raw_material_id,
                                                          )
                                                        : NO_LABEL
                                                }
                                                onValueChange={(value) =>
                                                    // Sin etiqueta tampoco hay etiquetas usadas: el campo se
                                                    // deshabilita y se vacía.
                                                    onChange(
                                                        index,
                                                        value === NO_LABEL
                                                            ? {
                                                                  label_raw_material_id:
                                                                      null,
                                                                  labels_used:
                                                                      '',
                                                              }
                                                            : {
                                                                  label_raw_material_id:
                                                                      Number(
                                                                          value,
                                                                      ),
                                                              },
                                                    )
                                                }
                                                disabled={isReadOnly}
                                            >
                                                <SelectTrigger
                                                    id={`packaging.${index}.label_raw_material_id`}
                                                    aria-label={`Etiqueta de ${pack.presentation}`}
                                                    className="h-8"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem
                                                        value={NO_LABEL}
                                                    >
                                                        Sin etiqueta
                                                    </SelectItem>
                                                    {labelOptions.map(
                                                        (label) => (
                                                            <SelectItem
                                                                key={label.id}
                                                                value={String(
                                                                    label.id,
                                                                )}
                                                            >
                                                                {label.is_active
                                                                    ? label.code
                                                                    : `${label.code} (inactiva)`}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </td>
                                        <td className="p-3">
                                            <IntegerInput
                                                id={`packaging.${index}.labels_used`}
                                                aria-label={`Etiquetas usadas de ${pack.presentation}`}
                                                className="h-8 text-right"
                                                value={pack.labels_used}
                                                placeholder={unitsPlaceholder}
                                                onValueChange={(value) =>
                                                    onChange(index, {
                                                        labels_used: value,
                                                    })
                                                }
                                                disabled={
                                                    isReadOnly ||
                                                    pack.label_raw_material_id ==
                                                        null
                                                }
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

function PackagingPlanForm({
    orderId,
    availableVariants,
}: {
    orderId: number;
    availableVariants: VariantOption[];
}) {
    const [variantId, setVariantId] = useState<number | null>(null);
    const [plannedUnits, setPlannedUnits] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});

    const variantOptions = availableVariants.map((variant) => ({
        id: variant.id,
        label: variantPresentationLabel(variant),
    }));

    const handleAdd = () => {
        if (!variantId || !plannedUnits) {
            const errors: Record<string, string> = {};

            if (!variantId) {
                errors.product_variant_id = 'Seleccione una presentación.';
            }

            if (!plannedUnits) {
                errors.planned_units = 'Ingrese unidades.';
            }

            setFormErrors(errors);

            return;
        }

        setSubmitting(true);
        setFormErrors({});

        router.post(
            storePackagingPlan({ production_order: orderId }).url,
            {
                product_variant_id: variantId,
                planned_units: plannedUnits,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setVariantId(null);
                    setPlannedUnits('');
                },
                onError: (errors) => {
                    setFormErrors(errors as Record<string, string>);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="space-y-3 rounded-md border border-dashed border-border bg-muted/30 p-3">
            <p className="text-xs text-muted-foreground">
                Agregar presentación al plan de envasado
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div className="space-y-1 sm:col-span-2">
                    <PresentationSelect
                        aria-label="Presentación"
                        options={variantOptions}
                        value={variantId}
                        onChange={(value) => setVariantId(Number(value))}
                    />
                    {formErrors.product_variant_id && (
                        <p className="text-xs text-destructive">
                            {formErrors.product_variant_id}
                        </p>
                    )}
                </div>
                <div className="space-y-1">
                    <IntegerInput
                        placeholder="Unidades planeadas"
                        className="h-9"
                        value={plannedUnits}
                        onValueChange={setPlannedUnits}
                    />
                    {formErrors.planned_units && (
                        <p className="text-xs text-destructive">
                            {formErrors.planned_units}
                        </p>
                    )}
                </div>
            </div>
            {formErrors.production_order && (
                <p className="text-xs text-destructive">
                    {formErrors.production_order}
                </p>
            )}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleAdd}
                disabled={submitting}
            >
                {submitting ? 'Guardando...' : 'Agregar presentación'}
            </Button>
        </div>
    );
}
