import { FormattedNumber } from '@/components/formatted-number';
import { ProductSourcePicker } from '@/components/production/product-source-picker';
import {
    BatchSourceItem,
    RemnantSourceItem,
} from '@/components/production/products-to-use/product-source-items';
import type { ProductsToUseCardProps } from '@/components/production/products-to-use/types';
import { lineOverfillWarning } from '@/lib/batch-usage';
import { parseDecimal } from '@/lib/numeric-input';
import type {
    ProductionOrder,
    ProductionOrderBatchConsumption,
    ProductionOrderProductLine,
    ProductionOrderProductLineUsageRow,
    ProductionOrderRemnantConsumption,
} from '@/types/production-orders';

type ProductLineBlockProps = Omit<
    ProductsToUseCardProps,
    'order' | 'canPlan' | 'lineUsageRows'
> & {
    /** Lo gastado de la línea, tal como se escribe en la tabla (o lo guardado). */
    lineActualKg: ProductionOrderProductLineUsageRow['actual_kg'] | null;
    orderId: number;
    line: ProductionOrderProductLine;
    remnantSources: ProductionOrderRemnantConsumption[];
    batchSources: ProductionOrderBatchConsumption[];
    availableRemnants: NonNullable<ProductionOrder['available_remnants']>;
    availableBatches: NonNullable<ProductionOrder['available_batches']>;
    canPlan: boolean;
};

/** Una línea: el producto, lo planeado, lo que cubren sus fuentes, sus fuentes y el buscador para agregar otra. */
export function ProductLineBlock({
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
    lineActualKg,
    onUsageChange,
    errors,
    previewProductCosts,
}: ProductLineBlockProps) {
    const hasSources = remnantSources.length + batchSources.length > 0;
    // La cuenta en unidades y la sugerencia de bajarlas solo tienen sentido si la línea sale de un solo lote.
    const singleLot =
        remnantSources.length === 0 && batchSources.length === 1
            ? batchSources[0]
            : null;
    const leftoverTotal = batchSources
        .map((source) => usageRows.find((row) => row.id === source.id))
        .filter((row) => row?.has_leftover)
        .reduce((sum, row) => sum + (parseDecimal(row?.leftover_kg) ?? 0), 0);
    // Con un lote sin peso por galón no se sabe cuánto traen las fuentes: no hay contra qué comparar.
    const overfill =
        canRecordUsage && !line.has_unknown_kg
            ? lineOverfillWarning(lineActualKg, leftoverTotal, line.covered_kg)
            : null;
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
                <FormattedNumber value={line.covered_kg} kind="quantity" />
                {line.planned_kg !== null && (
                    <>
                        {' de '}
                        <FormattedNumber
                            value={line.planned_kg}
                            kind="quantity"
                        />
                    </>
                )}{' '}
                kg
                {line.missing_kg !== null && hasSources && (
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
                <p className="text-sm font-medium text-warning-text">
                    Elige de dónde sale: un saldo o un lote.
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
                                lineActualKg={
                                    singleLot?.id === source.id
                                        ? lineActualKg
                                        : null
                                }
                            />
                        );
                    })}
                </ul>
            )}
            {overfill && (
                <p className="text-xs text-warning-text">{overfill}</p>
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
                    missingKg={line.missing_kg}
                    label={hasSources ? 'Agregar otra fuente' : 'De dónde sale'}
                />
            )}
        </section>
    );
}
