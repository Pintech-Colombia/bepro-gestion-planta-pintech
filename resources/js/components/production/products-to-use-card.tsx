import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';

import { FormError } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { ProductSourcePicker } from '@/components/production/product-source-picker';
import { ProductLineBlock } from '@/components/production/products-to-use/product-line-block';
import type { ProductsToUseCardProps } from '@/components/production/products-to-use/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

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
 * «Productos a usar»: de dónde sale el producto terminado que la orden lleva como ingrediente. Lo gastado de cada producto
 * se escribe en la tabla de consumo, como una materia prima; aquí van sus fuentes (saldos en kilos y lotes en unidades
 * enteras, de la bodega de la orden), que alguien elige mientras la orden está abierta, y, al enviar a revisión o
 * completar, si quedó producto pesado en un envase abierto. Todo se descuenta al completar.
 */
export function ProductsToUseCard({
    order,
    canPlan,
    showCosts,
    canRecordUsage,
    usageRows,
    lineUsageRows,
    onUsageChange,
    errors,
    previewProductCosts,
}: ProductsToUseCardProps) {
    const [showAddProduct, setShowAddProduct] = useState(false);
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
    // Una línea que ya no es de la orden (otra persona le quitó su última fuente) vuelve en `product_lines.N.id`: sin
    // esto, enviar o completar fallaría sin decir nada. Lo gastado (`product_lines.N.actual_kg`) lo pinta la tabla.
    const productLineIdError = Object.entries(errors).find(([key]) =>
        /^product_lines\.\d+\.id$/.test(key),
    )?.[1];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Productos a usar</CardTitle>
                <CardDescription>
                    De dónde sale el producto terminado que lleva la orden. Lo
                    gastado se escribe en la tabla de consumo.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <FormError
                    id="product_lines"
                    message={errors.product_lines ?? productLineIdError}
                />
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
                        lineActualKg={
                            lineUsageRows.find((row) => row.id === line.id)
                                ?.actual_kg ?? line.actual_kg
                        }
                        onUsageChange={onUsageChange}
                        errors={errors}
                        previewProductCosts={previewProductCosts}
                    />
                ))}

                {canPlan && (
                    <section className="space-y-3 border-t border-border pt-4">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="-ml-2"
                            aria-expanded={showAddProduct}
                            onClick={() => setShowAddProduct((open) => !open)}
                        >
                            {showAddProduct ? (
                                <Minus className="size-4" />
                            ) : (
                                <Plus className="size-4" />
                            )}
                            Producto adicional
                        </Button>
                        {showAddProduct && (
                            <>
                                <p className="text-xs text-muted-foreground">
                                    Un saldo o un lote de un producto que la
                                    fórmula no lleva.
                                </p>
                                <ProductSourcePicker
                                    orderId={order.id}
                                    lineId={null}
                                    idPrefix="added-product"
                                    remnants={otherRemnants}
                                    batches={otherBatches}
                                    otherProducts
                                    label="De dónde sale"
                                />
                            </>
                        )}
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
