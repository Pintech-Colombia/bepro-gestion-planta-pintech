import { useState } from 'react';

import { IngredientsTable } from '@/components/production/ingredients-table';
import { LineAdjustmentsPanel } from '@/components/production/line-adjustments-panel';
import { OverconsumptionReasons } from '@/components/production/overconsumption-reasons';
import { ProductSourcePicker } from '@/components/production/product-source-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { PreviewStaleReason } from '@/hooks/use-server-preview';
import {
    materialCodesOf,
    materialReasonRows,
    productNamesOf,
    productReasonRows,
} from '@/lib/production-consumption';
import { cn } from '@/lib/utils';
import type {
    ProductionOrderAvailableBatch,
    ProductionOrderAvailableRemnant,
    ProductionOrderConsumptionReason,
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderIngredientFormRow,
    ProductionOrderLineAdjustment,
    ProductionOrderOverconsumption,
    ProductionOrderProductConsumptionReason,
    ProductionOrderProductOverconsumption,
    ProductionOrderSetData,
    RawMaterialOption,
    ProductTableRow,
} from '@/types/production-orders';

type OpConsumptionSectionProps = {
    orderId: number;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: ProductionOrderErrors;
    ingredientRows: ProductionOrderIngredientFormRow[];
    lineAdjustments: ProductionOrderLineAdjustment[];
    rawMaterials: RawMaterialOption[];
    isCompleted: boolean;
    isReadOnly: boolean;
    showCosts: boolean;
    previewLoading: boolean;
    /** Por qué los costos mostrados no corresponden a lo escrito (null si están al día). */
    previewStale?: PreviewStaleReason;
    overconsumption: ProductionOrderOverconsumption[];
    consumptionReasons: ProductionOrderConsumptionReason[];
    productOverconsumption: ProductionOrderProductOverconsumption[];
    productConsumptionReasons: ProductionOrderProductConsumptionReason[];
    /** Los productos que la orden lleva como ingrediente: renglones de la tabla de consumo. */
    productRows: ProductTableRow[];
    onProductActualChange: (index: number, value: string) => void;
    /**
     * Sin «Productos a usar» (la orden no lleva ninguno) y con permiso de planear: el botón «Agregar saldo o lote» del
     * encabezado abre el mismo selector de esa sección. null: sin botón.
     */
    addProductSources: {
        remnants: ProductionOrderAvailableRemnant[];
        batches: ProductionOrderAvailableBatch[];
    } | null;
};

/** Sección 2 de la OP: lo real que se gastó, sus ajustes de línea y, si se pasó de lo planeado, el motivo. */
export function OpConsumptionSection({
    orderId,
    data,
    setData,
    errors,
    ingredientRows,
    lineAdjustments,
    rawMaterials,
    isCompleted,
    isReadOnly,
    showCosts,
    previewLoading,
    previewStale = null,
    overconsumption,
    consumptionReasons,
    productOverconsumption,
    productConsumptionReasons,
    productRows,
    onProductActualChange,
    addProductSources,
}: OpConsumptionSectionProps) {
    const [showAdjustmentForm, setShowAdjustmentForm] = useState(false);
    const [showAddProduct, setShowAddProduct] = useState(false);
    const fieldErrors = errors as Record<string, string | undefined>;

    const materialCodes = materialCodesOf(ingredientRows);
    const productNames = productNamesOf(productRows.map((row) => row.line));

    const showPreviewStatus = !isCompleted && showCosts;
    const previewMessage = previewLoading
        ? 'Recalculando costos...'
        : previewStale === 'invalid'
          ? 'Hay un valor que no es válido: los costos mostrados son de la última vista previa correcta. Al guardar verás el error.'
          : previewStale === 'failed'
            ? 'No se pudo recalcular: los costos mostrados son de la última vista previa correcta.'
            : '';

    return (
        <Card>
            <CardHeader className="flex-row flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1.5">
                    <CardTitle>Consumo de materias primas</CardTitle>
                    <CardDescription>
                        Lo real que se gastó en cada paso.
                        {showPreviewStatus &&
                            ' Los costos se recalculan mientras escribes.'}
                    </CardDescription>
                    {showPreviewStatus && (
                        <p
                            aria-live="polite"
                            className={cn(
                                'min-h-4 text-xs',
                                previewStale && !previewLoading
                                    ? 'text-warning-text'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {previewMessage}
                        </p>
                    )}
                </div>
                {!isReadOnly && (
                    <div className="flex flex-wrap gap-2">
                        {addProductSources && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-expanded={showAddProduct}
                                onClick={() =>
                                    setShowAddProduct((open) => !open)
                                }
                            >
                                Agregar saldo o lote
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            aria-expanded={showAdjustmentForm}
                            onClick={() =>
                                setShowAdjustmentForm((open) => !open)
                            }
                        >
                            Agregar ajuste de línea
                        </Button>
                    </div>
                )}
            </CardHeader>
            <CardContent className="space-y-6">
                {addProductSources && showAddProduct && !isReadOnly && (
                    <div className="space-y-2 rounded-md border border-dashed border-border bg-muted/30 p-3">
                        <p className="text-xs text-muted-foreground">
                            Un saldo o un lote de producto terminado que la
                            fórmula no lleva.
                        </p>
                        <ProductSourcePicker
                            orderId={orderId}
                            lineId={null}
                            idPrefix="added-product"
                            remnants={addProductSources.remnants}
                            batches={addProductSources.batches}
                            otherProducts
                            label="De dónde sale"
                        />
                    </div>
                )}

                <IngredientsTable
                    rows={ingredientRows}
                    data={data}
                    setData={setData}
                    isReadOnly={isReadOnly}
                    showCosts={showCosts}
                    overconsumption={overconsumption}
                    productOverconsumption={productOverconsumption}
                    productRows={productRows}
                    onProductActualChange={onProductActualChange}
                    errors={fieldErrors}
                />

                <LineAdjustmentsPanel
                    orderId={orderId}
                    adjustments={lineAdjustments}
                    rawMaterials={rawMaterials}
                    isCompleted={isCompleted}
                    isReadOnly={isReadOnly}
                    showForm={showAdjustmentForm}
                    onFormDone={() => setShowAdjustmentForm(false)}
                />

                <OverconsumptionReasons
                    field="overconsumption_reasons"
                    title="Consumo por encima de lo planeado"
                    description="Por materia prima, sumando todos sus pasos y ajustes de línea."
                    rows={materialReasonRows(overconsumption)}
                    storedIds={consumptionReasons.map(
                        (reason) => reason.raw_material_id,
                    )}
                    labels={materialCodes}
                    fallbackLabel={(id) => `MP #${id}`}
                    data={data}
                    setData={setData}
                    errors={fieldErrors}
                    isReadOnly={isReadOnly}
                    showCosts={showCosts}
                />
                <OverconsumptionReasons
                    field="product_overconsumption_reasons"
                    title="Producto terminado por encima de lo planeado"
                    description="Por producto, sumando todas sus líneas, en kilos."
                    rows={productReasonRows(productOverconsumption)}
                    storedIds={productConsumptionReasons.map(
                        (reason) => reason.product_id,
                    )}
                    labels={productNames}
                    fallbackLabel={(id) => `PT #${id}`}
                    data={data}
                    setData={setData}
                    errors={fieldErrors}
                    isReadOnly={isReadOnly}
                    showCosts={showCosts}
                />
            </CardContent>
        </Card>
    );
}
