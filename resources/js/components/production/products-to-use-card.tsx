import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import {
    destroy as removeBatchConsumption,
    update as updateBatchConsumption,
} from '@/actions/App/Http/Controllers/Production/BatchConsumptionController';
import { destroy as removeRemnantConsumption } from '@/actions/App/Http/Controllers/Production/RemnantConsumptionController';
import { DecimalInput } from '@/components/decimal-input';
import { FormError, FormField } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { IntegerInput } from '@/components/integer-input';
import {
    ProductSourcePicker,
    preventSubmitOnEnter,
} from '@/components/production/product-source-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    batchOverfillWarning,
    batchUnitsSuggestion,
    batchUsageSummary,
} from '@/lib/batch-usage';
import { formatKind } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';
import type {
    ProductionOrder,
    ProductionOrderBatchConsumption,
    ProductionOrderBatchUsageRow,
    ProductionOrderProductLine,
    ProductionOrderRemnantConsumption,
    ProductionOrderSourceState,
} from '@/types/production-orders';

const STATE_LABELS: Record<
    Exclude<ProductionOrderSourceState, 'planned'>,
    string
> = {
    applied: 'Descontado del inventario',
    unused: 'No se usó: la orden ya no está abierta',
};

type ProductsToUseCardProps = {
    order: ProductionOrder;
    /** Agregar, cambiar y quitar fuentes (`can.planRemnant`). */
    canPlan: boolean;
    /** Los costos, solo con costs.view. */
    showCosts: boolean;
    /** Se escriben los kilos usados y lo que quedó: la orden se está entregando (enviar a revisión o completar). */
    canRecordUsage: boolean;
    usageRows: ProductionOrderBatchUsageRow[];
    onUsageChange: (
        index: number,
        patch: Partial<Omit<ProductionOrderBatchUsageRow, 'id'>>,
    ) => void;
    /** Los errores del formulario de la orden (enviar o completar). */
    errors: Record<string, string | undefined>;
    /** El costo de cada lote según la vista previa, con lo que quedó tal como se escribe. */
    previewProductCosts: Map<number, string | null> | null;
};

/** El primer error cuya llave empieza por `prefix` (`remnant_consumption_ids.0`, por ejemplo). */
function firstErrorWithPrefix(
    errors: Record<string, string | undefined>,
    prefix: string,
): string | undefined {
    const key = Object.keys(errors).find(
        (errorKey) => errorKey === prefix || errorKey.startsWith(`${prefix}.`),
    );

    return key ? errors[key] : undefined;
}

/**
 * «Productos a usar»: el producto terminado que la orden lleva como ingrediente y de dónde sale cada kilo. Cada línea (de
 * la fórmula, con sus kilos planeados, o agregada en la orden) tiene sus fuentes: saldos en kilos y lotes en unidades
 * enteras, de la bodega de la orden. Se agregan, cambian y quitan mientras la orden está abierta; al enviar a revisión o
 * completar se escriben, por lote, los kilos usados y lo que quedó pesado en un envase abierto. Todo se descuenta al
 * completar.
 */
export function ProductsToUseCard({
    order,
    canPlan,
    showCosts,
    canRecordUsage,
    usageRows,
    onUsageChange,
    errors,
    previewProductCosts,
}: ProductsToUseCardProps) {
    const lines = order.product_lines ?? [];
    const remnantSources = order.remnant_consumptions ?? [];
    const batchSources = order.batch_consumptions ?? [];
    const availableRemnants = order.available_remnants ?? [];
    const availableBatches = order.available_batches ?? [];

    if (!canPlan && lines.length === 0 && !order.remnant) {
        return null;
    }

    const lineProductIds = new Set(lines.map((line) => line.product_id));
    const otherRemnants = availableRemnants.filter(
        (remnant) => !lineProductIds.has(remnant.product_id),
    );
    const otherBatches = availableBatches.filter(
        (batch) => !lineProductIds.has(batch.product_id),
    );
    const remnantIdsError = firstErrorWithPrefix(
        errors,
        'remnant_consumption_ids',
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Productos a usar</CardTitle>
                <CardDescription>
                    Producto terminado que lleva la orden: saldos en kilos o
                    lotes en unidades enteras de esta bodega. Se descuentan al
                    completar la orden.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <FormError id="product_lines" message={errors.product_lines} />
                <FormError
                    id="batch_consumptions"
                    message={errors.batch_consumptions}
                />
                <FormError
                    id="remnant_consumption_ids"
                    message={remnantIdsError}
                />

                {lines.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Esta orden no lleva productos.
                    </p>
                )}

                {lines.map((line) => (
                    <ProductLineBlock
                        key={line.id}
                        orderId={order.id}
                        line={line}
                        remnantSources={remnantSources.filter(
                            (source) =>
                                source.production_order_product_line_id ===
                                line.id,
                        )}
                        batchSources={batchSources.filter(
                            (source) =>
                                source.production_order_product_line_id ===
                                line.id,
                        )}
                        availableRemnants={availableRemnants.filter(
                            (remnant) => remnant.product_id === line.product_id,
                        )}
                        availableBatches={availableBatches.filter(
                            (batch) => batch.product_id === line.product_id,
                        )}
                        canPlan={canPlan}
                        showCosts={showCosts}
                        canRecordUsage={canRecordUsage}
                        usageRows={usageRows}
                        onUsageChange={onUsageChange}
                        errors={errors}
                        previewProductCosts={previewProductCosts}
                    />
                ))}

                {canPlan && (
                    <section className="space-y-3 border-t border-border pt-4">
                        <div>
                            <h3 className="text-base font-semibold">
                                Agregar producto
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Un saldo o un lote de un producto que la fórmula
                                no lleva.
                            </p>
                        </div>
                        <ProductSourcePicker
                            orderId={order.id}
                            lineId={null}
                            idPrefix="added-product"
                            remnants={otherRemnants}
                            batches={otherBatches}
                            label="Saldo o lote"
                            emptyText="No hay saldos ni lotes de otros productos en esta bodega."
                        />
                    </section>
                )}

                {order.remnant && (
                    <section className="space-y-1 border-t border-border pt-4">
                        <h3 className="text-base font-semibold">
                            Saldo que dejó esta orden
                        </h3>
                        <p className="text-sm">
                            <FormattedNumber
                                value={order.remnant.original_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg (≈{' '}
                            <FormattedNumber
                                value={order.remnant.original_quantity_gallons}
                                kind="quantity"
                            />{' '}
                            gal)
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {order.remnant.status_label} · Disponible:{' '}
                            <FormattedNumber
                                value={order.remnant.available_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg
                        </p>
                    </section>
                )}
            </CardContent>
        </Card>
    );
}

type ProductLineBlockProps = Omit<
    ProductsToUseCardProps,
    'order' | 'canPlan'
> & {
    orderId: number;
    line: ProductionOrderProductLine;
    remnantSources: ProductionOrderRemnantConsumption[];
    batchSources: ProductionOrderBatchConsumption[];
    availableRemnants: NonNullable<ProductionOrder['available_remnants']>;
    availableBatches: NonNullable<ProductionOrder['available_batches']>;
    canPlan: boolean;
};

/** Una línea: el producto, lo planeado, lo que cubren sus fuentes, sus fuentes y el buscador para agregar otra. */
function ProductLineBlock({
    orderId,
    line,
    remnantSources,
    batchSources,
    availableRemnants,
    availableBatches,
    canPlan,
    showCosts,
    canRecordUsage,
    usageRows,
    onUsageChange,
    errors,
    previewProductCosts,
}: ProductLineBlockProps) {
    const hasSources = remnantSources.length + batchSources.length > 0;
    const takenBatchIds = new Set(
        batchSources.map((source) => source.finished_product_batch_id),
    );
    // Solo para decidir el tono: lo que falta lo calcula el servidor.
    const isMissing = (parseDecimal(line.missing_kg) ?? 0) > 0;
    const origin =
        line.origin === 'formula'
            ? line.step_order !== null
                ? `Paso ${line.step_order} de la fórmula`
                : 'De la fórmula'
            : 'Agregado en la orden';

    return (
        <section className="space-y-3 border-t border-border pt-4 first-of-type:border-t-0 first-of-type:pt-0">
            <div>
                <h3 className="text-base font-semibold">{line.product_name}</h3>
                <p className="text-xs text-muted-foreground">{origin}</p>
            </div>

            <p className="text-sm">
                {line.planned_kg !== null && (
                    <>
                        <FormattedNumber
                            value={line.planned_kg}
                            kind="quantity"
                        />{' '}
                        kg planeados ·{' '}
                    </>
                )}
                <FormattedNumber value={line.covered_kg} kind="quantity" /> kg
                cubiertos
                {line.missing_kg !== null && (
                    <>
                        {' · '}
                        {isMissing ? (
                            <span className="font-medium text-warning-text">
                                faltan{' '}
                                <FormattedNumber
                                    value={line.missing_kg}
                                    kind="quantity"
                                />{' '}
                                kg
                            </span>
                        ) : (
                            <span className="text-success-text">cubierto</span>
                        )}
                    </>
                )}
            </p>
            {line.has_unknown_kg && (
                <p className="text-xs text-muted-foreground">
                    Un lote no tiene peso por galón: sus kilos no suman en lo
                    cubierto.
                </p>
            )}

            {!hasSources && line.origin === 'formula' && (
                <p className="text-sm text-warning-text">
                    Sin fuentes: agrega un saldo o un lote antes de entregar la
                    orden.
                </p>
            )}

            {hasSources && (
                <ul className="space-y-3">
                    {remnantSources.map((source) => (
                        <RemnantSourceItem
                            key={`saldo-${source.id}`}
                            orderId={orderId}
                            source={source}
                            showCosts={showCosts}
                        />
                    ))}
                    {batchSources.map((source) => {
                        const index = usageRows.findIndex(
                            (row) => row.id === source.id,
                        );

                        return (
                            <BatchSourceItem
                                key={`lote-${source.id}`}
                                orderId={orderId}
                                source={source}
                                showCosts={showCosts}
                                canRecordUsage={canRecordUsage}
                                usageIndex={index}
                                usage={
                                    index >= 0 ? usageRows[index] : undefined
                                }
                                onUsageChange={onUsageChange}
                                errors={errors}
                                previewCost={previewProductCosts?.get(
                                    source.id,
                                )}
                            />
                        );
                    })}
                </ul>
            )}

            {canPlan && (
                <ProductSourcePicker
                    orderId={orderId}
                    lineId={line.id}
                    idPrefix={`product-line-${line.id}`}
                    remnants={availableRemnants}
                    batches={availableBatches.filter(
                        (batch) => !takenBatchIds.has(batch.id),
                    )}
                    label={
                        hasSources ? 'Agregar otra fuente' : 'Agregar fuente'
                    }
                    emptyText="No hay más saldos ni lotes libres de este producto en la bodega."
                />
            )}
        </section>
    );
}

/** Quitar una fuente: un solo toque (también las preseleccionadas). No se confirma: se vuelve a agregar igual de fácil. */
function RemoveSourceButton({
    label,
    onRemove,
    disabled,
}: {
    label: string;
    onRemove: () => void;
    disabled: boolean;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="shrink-0"
                    onClick={onRemove}
                    disabled={disabled}
                >
                    <X className="size-4" />
                    <span className="sr-only">{label}</span>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/** El marco de una fuente: qué es (título y origen), el botón de quitar y lo demás debajo. */
function SourceFrame({
    title,
    subtitle,
    action,
    children,
}: {
    title: ReactNode;
    subtitle: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <li className="space-y-3 rounded-md border border-border p-3">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-sm font-medium">{title}</p>
                    <p className="text-xs text-muted-foreground">{subtitle}</p>
                </div>
                {action}
            </div>
            {children}
        </li>
    );
}

function useRemove(url: string, errorKeys: string[]) {
    const [removing, setRemoving] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const remove = () => {
        setRemoving(true);
        setError(undefined);
        router.delete(url, {
            preserveScroll: true,
            onError: (serverErrors: Record<string, string>) =>
                setError(
                    errorKeys
                        .map((key) => serverErrors[key])
                        .find((message) => message !== undefined),
                ),
            onFinish: () => setRemoving(false),
        });
    };

    return { remove, removing, error };
}

function RemnantSourceItem({
    orderId,
    source,
    showCosts,
}: {
    orderId: number;
    source: ProductionOrderRemnantConsumption;
    showCosts: boolean;
}) {
    const { remove, removing, error } = useRemove(
        removeRemnantConsumption({
            production_order: orderId,
            consumption: source.id,
        }).url,
        ['remnant_consumption', 'production_order'],
    );
    const isPlanned = source.state === 'planned';

    return (
        <SourceFrame
            title={`Saldo · ${source.source_product_name}`}
            subtitle={
                <>
                    Lote {source.source_lot_number} ·{' '}
                    <span className="font-mono">
                        {source.source_order_number}
                    </span>
                </>
            }
            action={
                source.can.remove && (
                    <RemoveSourceButton
                        label="Quitar saldo"
                        onRemove={remove}
                        disabled={removing}
                    />
                )
            }
        >
            <p className="text-sm">
                <FormattedNumber value={source.quantity_kg} kind="quantity" />{' '}
                kg
                {source.state !== 'unused' && (
                    <span className="text-muted-foreground">
                        {' '}
                        ({isPlanned && '≈ '}
                        <FormattedNumber
                            value={source.quantity_gallons}
                            kind="quantity"
                        />{' '}
                        gal)
                    </span>
                )}
                {showCosts && source.state !== 'unused' && (
                    <>
                        {' · Costo '}
                        {isPlanned && '≈ '}
                        <FormattedNumber
                            value={source.consumed_cost}
                            kind="cost"
                            emptyValue="sin estimar"
                        />
                    </>
                )}
            </p>
            {source.notes && (
                <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                    {source.notes}
                </p>
            )}
            {source.state !== 'planned' && (
                <p className="text-xs text-muted-foreground">
                    {STATE_LABELS[source.state]}
                </p>
            )}
            <FormError message={error} />
        </SourceFrame>
    );
}

type BatchSourceItemProps = {
    orderId: number;
    source: ProductionOrderBatchConsumption;
    showCosts: boolean;
    canRecordUsage: boolean;
    /** La posición del lote en `batch_consumptions` del formulario: la de sus llaves de error. -1 mientras se sincroniza. */
    usageIndex: number;
    usage: ProductionOrderBatchUsageRow | undefined;
    onUsageChange: ProductsToUseCardProps['onUsageChange'];
    errors: Record<string, string | undefined>;
    /** undefined: la vista previa no lo trae; null: no se puede estimar. */
    previewCost: string | null | undefined;
};

function BatchSourceItem({
    orderId,
    source,
    showCosts,
    canRecordUsage,
    usageIndex,
    usage,
    onUsageChange,
    errors,
    previewCost,
}: BatchSourceItemProps) {
    const { remove, removing, error } = useRemove(
        removeBatchConsumption({
            production_order: orderId,
            batch_consumption: source.id,
        }).url,
        ['batch_consumption', 'production_order'],
    );
    const isPlanned = source.state === 'planned';
    const cost = previewCost !== undefined ? previewCost : source.consumed_cost;
    const errorKey = (field: string) =>
        `batch_consumptions.${usageIndex}.${field}`;

    return (
        <SourceFrame
            title={
                <>
                    Lote <span className="font-mono">{source.lot_number}</span>
                    {source.presentation_label &&
                        ` · ${source.presentation_label}`}
                </>
            }
            subtitle={
                <>
                    {source.source_product_name} ·{' '}
                    <span className="font-mono">
                        {source.source_order_number}
                    </span>
                </>
            }
            action={
                source.can.remove && (
                    <RemoveSourceButton
                        label="Quitar lote"
                        onRemove={remove}
                        disabled={removing}
                    />
                )
            }
        >
            {source.can.update ? (
                <BatchUnitsEditor
                    key={`${source.id}-${source.units}`}
                    orderId={orderId}
                    source={source}
                />
            ) : (
                <p className="text-sm">
                    {formatKind(source.units, 'units')}{' '}
                    {source.units === 1 ? 'unidad' : 'unidades'}
                    {source.kg !== null && (
                        <>
                            {' · '}
                            <FormattedNumber
                                value={source.kg}
                                kind="quantity"
                            />{' '}
                            kg
                        </>
                    )}
                </p>
            )}

            {showCosts && source.state !== 'unused' && (
                <p className="text-xs text-muted-foreground">
                    Costo {isPlanned && '≈ '}
                    <FormattedNumber
                        value={cost}
                        kind="cost"
                        emptyValue="sin estimar"
                    />
                    {source.transfer_unit_cost != null && (
                        <>
                            {' · traslado '}
                            <FormattedNumber
                                value={source.transfer_unit_cost}
                                kind="cost"
                            />{' '}
                            por unidad
                        </>
                    )}
                </p>
            )}

            {canRecordUsage && isPlanned && usage && usageIndex >= 0 ? (
                <BatchUsageFields
                    source={source}
                    usage={usage}
                    onChange={(patch) => onUsageChange(usageIndex, patch)}
                    fieldId={errorKey}
                    errors={errors}
                />
            ) : (
                <SavedUsage source={source} />
            )}

            {source.state !== 'planned' && (
                <p className="text-xs text-muted-foreground">
                    {STATE_LABELS[source.state]}
                </p>
            )}
            <FormError
                id={usageIndex >= 0 ? errorKey('id') : undefined}
                message={
                    (usageIndex >= 0 ? errors[errorKey('id')] : undefined) ??
                    error
                }
            />
        </SourceFrame>
    );
}

/** Cambiar las unidades de un lote: se guarda al momento, como agregar o quitar. */
function BatchUnitsEditor({
    orderId,
    source,
}: {
    orderId: number;
    source: ProductionOrderBatchConsumption;
}) {
    const [units, setUnits] = useState(String(source.units));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const isDirty = units !== String(source.units);

    const save = () => {
        if (!isDirty || saving) {
            return;
        }

        setSaving(true);
        setError(undefined);
        router.patch(
            updateBatchConsumption({
                production_order: orderId,
                batch_consumption: source.id,
            }).url,
            { units },
            {
                preserveScroll: true,
                onError: (serverErrors: Record<string, string>) =>
                    setError(
                        serverErrors.units ?? serverErrors.production_order,
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="space-y-2">
            <FormField
                id={`batch-source-${source.id}-units`}
                label="Unidades"
                help={
                    source.kg_per_unit !== null ? (
                        <>
                            <FormattedNumber
                                value={source.kg_per_unit}
                                kind="quantity"
                            />{' '}
                            kg por unidad
                            {source.kg !== null && (
                                <>
                                    {' · '}
                                    <FormattedNumber
                                        value={source.kg}
                                        kind="quantity"
                                    />{' '}
                                    kg en total
                                </>
                            )}
                        </>
                    ) : (
                        'Sin peso por galón: no se sabe cuántos kilos trae'
                    )
                }
                error={error}
            >
                <IntegerInput
                    value={units}
                    onValueChange={setUnits}
                    onKeyDown={(event) => {
                        preventSubmitOnEnter(event);

                        if (event.key === 'Enter') {
                            save();
                        }
                    }}
                />
            </FormField>
            {isDirty && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={save}
                    disabled={saving}
                >
                    {saving ? 'Guardando...' : 'Guardar unidades'}
                </Button>
            )}
        </div>
    );
}

/** Al enviar o completar: los kilos usados del lote, la cuenta en unidades y lo que quedó, pesado. */
function BatchUsageFields({
    source,
    usage,
    onChange,
    fieldId,
    errors,
}: {
    source: ProductionOrderBatchConsumption;
    usage: ProductionOrderBatchUsageRow;
    onChange: (
        patch: Partial<Omit<ProductionOrderBatchUsageRow, 'id'>>,
    ) => void;
    fieldId: (field: string) => string;
    errors: Record<string, string | undefined>;
}) {
    const leftover = usage.has_leftover ? usage.leftover_kg : '';
    const summary = batchUsageSummary(usage.used_kg, source.kg_per_unit);
    const suggestion = batchUnitsSuggestion(
        usage.used_kg,
        leftover,
        source.kg_per_unit,
        source.units,
    );
    const overfill = batchOverfillWarning(
        usage.used_kg,
        leftover,
        source.kg_per_unit,
        source.units,
    );

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <FormField
                    id={fieldId('used_kg')}
                    label="Kilos usados"
                    required
                    help={
                        summary ?? 'Lo que se agregó de este lote a la mezcla.'
                    }
                    error={errors[fieldId('used_kg')]}
                >
                    <DecimalInput
                        placeholder="0,00"
                        value={usage.used_kg}
                        onValueChange={(value) => onChange({ used_kg: value })}
                    />
                </FormField>
                <FormField
                    id={fieldId('has_leftover')}
                    label="¿Quedó producto en un cuñete abierto?"
                    error={errors[fieldId('has_leftover')]}
                >
                    {(control) => (
                        <Select
                            value={usage.has_leftover ? 'yes' : 'no'}
                            onValueChange={(value) =>
                                onChange({ has_leftover: value === 'yes' })
                            }
                        >
                            <SelectTrigger {...control}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="no">No</SelectItem>
                                <SelectItem value="yes">Sí</SelectItem>
                            </SelectContent>
                        </Select>
                    )}
                </FormField>
                {usage.has_leftover && (
                    <FormField
                        id={fieldId('leftover_kg')}
                        label="Kilos que quedaron"
                        required
                        help={
                            source.kg_per_unit !== null
                                ? `Pésalo en la báscula: vuelve al saldo de ${source.source_order_number ?? 'su OP'}. No pasa de una unidad (${formatKind(source.kg_per_unit, 'quantity')} kg).`
                                : `Pésalo en la báscula: vuelve al saldo de ${source.source_order_number ?? 'su OP'}.`
                        }
                        error={errors[fieldId('leftover_kg')]}
                        className="sm:col-span-2"
                    >
                        <DecimalInput
                            placeholder="0,00"
                            value={usage.leftover_kg}
                            onValueChange={(value) =>
                                onChange({ leftover_kg: value })
                            }
                        />
                    </FormField>
                )}
            </div>
            {suggestion && (
                <p className="text-xs text-muted-foreground">{suggestion}</p>
            )}
            {overfill && (
                <p className="text-xs text-warning-text">{overfill}</p>
            )}
        </div>
    );
}

/** Lo que se registró al enviar a revisión o al completar, sin campos. */
function SavedUsage({ source }: { source: ProductionOrderBatchConsumption }) {
    if (source.used_kg === null) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            Usados: <FormattedNumber value={source.used_kg} kind="quantity" />{' '}
            kg
            {source.leftover_kg !== null ? (
                <>
                    {' · quedaron '}
                    <FormattedNumber
                        value={source.leftover_kg}
                        kind="quantity"
                    />{' '}
                    kg pesados en un cuñete abierto
                </>
            ) : (
                ' · no quedó producto en un cuñete abierto'
            )}
        </p>
    );
}
