import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import { destroy as removeBatchConsumption } from '@/actions/App/Http/Controllers/Production/BatchConsumptionController';
import { destroy as removeRemnantConsumption } from '@/actions/App/Http/Controllers/Production/RemnantConsumptionController';
import { FormError } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import {
    BatchUnitsEditor,
    BatchUsageFields,
    SavedUsage,
} from '@/components/production/products-to-use/batch-usage-fields';
import type { ProductsToUseCardProps } from '@/components/production/products-to-use/types';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatKind } from '@/lib/formatters';
import type {
    ProductionOrderBatchConsumption,
    ProductionOrderBatchUsageRow,
    ProductionOrderProductLineUsageRow,
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

export function RemnantSourceItem({
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
            title={`Saldo · Lote ${source.source_lot_number}`}
            subtitle={
                <span className="font-mono">{source.source_order_number}</span>
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
    /** Lo gastado de la línea si sale solo de este lote: para la cuenta en unidades; null si no. */
    lineActualKg: ProductionOrderProductLineUsageRow['actual_kg'] | null;
};

export function BatchSourceItem({
    orderId,
    source,
    showCosts,
    canRecordUsage,
    usageIndex,
    usage,
    onUsageChange,
    errors,
    previewCost,
    lineActualKg,
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
                <span className="font-mono">{source.source_order_number}</span>
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
                    lineActualKg={lineActualKg}
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
