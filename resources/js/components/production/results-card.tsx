import { Beaker } from 'lucide-react';

import { DecimalInput } from '@/components/decimal-input';
import { IngredientsTable } from '@/components/production/ingredients-table';
import { LineAdjustmentsPanel } from '@/components/production/line-adjustments-panel';
import { OverconsumptionReasons } from '@/components/production/overconsumption-reasons';
import { PackagingSection } from '@/components/production/packaging-section';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import type { PreviewStaleReason } from '@/hooks/use-server-preview';
import { HEGMAN_LIMITS, NUMBER_TYPES } from '@/lib/number-types';
import type {
    LabelMaterialOption,
    ProductionOrderConsumptionReason,
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderIngredientFormRow,
    ProductionOrderLineAdjustment,
    ProductionOrderOverconsumption,
    ProductionOrderPackagingCost,
    ProductionOrderPackagingFormRow,
    ProductionOrderSetData,
    RawMaterialOption,
    VariantOption,
} from '@/types/production-orders';

type ResultsCardProps = {
    orderId: number;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: ProductionOrderErrors;
    ingredientRows: ProductionOrderIngredientFormRow[];
    packagingRows: ProductionOrderPackagingFormRow[];
    /** Costo de cada presentación por id del plan, del resumen de costos. */
    packagingCosts?: ReadonlyMap<number, ProductionOrderPackagingCost>;
    lineAdjustments: ProductionOrderLineAdjustment[];
    rawMaterials: RawMaterialOption[];
    labelMaterials: LabelMaterialOption[];
    availableVariants: VariantOption[];
    isCompleted: boolean;
    isReadOnly: boolean;
    previewLoading: boolean;
    /** Por qué los costos mostrados no corresponden a lo escrito (null si están al día). */
    previewStale?: PreviewStaleReason;
    solidsReferenceLabel: string | null;
    showCosts?: boolean;
    canPrintLabels?: boolean;
    /** Lo que el servidor marca como pasado de lo planeado, y los motivos guardados. */
    overconsumption?: ProductionOrderOverconsumption[];
    consumptionReasons?: ProductionOrderConsumptionReason[];
};

export function ResultsCard({
    orderId,
    data,
    setData,
    errors,
    ingredientRows,
    packagingRows,
    packagingCosts,
    lineAdjustments,
    rawMaterials,
    labelMaterials,
    availableVariants,
    isCompleted,
    isReadOnly,
    previewLoading,
    previewStale = null,
    solidsReferenceLabel,
    showCosts = true,
    canPrintLabels = false,
    overconsumption = [],
    consumptionReasons = [],
}: ResultsCardProps) {
    const materialCodes = new Map<number, string>();

    for (const row of ingredientRows) {
        if (!materialCodes.has(row.raw_material_id)) {
            materialCodes.set(
                row.raw_material_id,
                row.raw_material_name ?? `MP #${row.raw_material_id}`,
            );
        }
    }

    return (
        <Card className={isCompleted ? 'opacity-90 shadow-none' : ''}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Beaker className="h-5 w-5 text-primary" />
                    Resultados de Fabricación
                </CardTitle>
                <CardDescription>
                    Ingrese los datos reales obtenidos al finalizar el proceso.
                    {!isCompleted && showCosts
                        ? ' Los costos se estiman en vivo desde servidor mientras editas.'
                        : ''}
                </CardDescription>
                {!isCompleted && showCosts && (
                    <p
                        className={`h-5 text-xs ${previewStale && !previewLoading ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'}`}
                    >
                        {previewLoading
                            ? 'Recalculando costos...'
                            : previewStale === 'invalid'
                              ? 'Hay un valor que no es válido: los costos mostrados son de la última vista previa correcta. Al guardar verás el error.'
                              : previewStale === 'failed'
                                ? 'No se pudo recalcular: los costos mostrados son de la última vista previa correcta.'
                                : ''}
                    </p>
                )}
            </CardHeader>
            <CardContent className="space-y-6">
                <div>
                    <div className="space-y-4 rounded-lg border border-dashed border-border/80 bg-muted/25 p-4">
                        <div className="space-y-1">
                            <p className="text-sm font-medium text-foreground">
                                Indicadores de laboratorio
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Viscosidad, molienda y sólidos se reflejan en el
                                certificado de calidad del lote al completar la
                                orden.
                            </p>
                        </div>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="viscosity">
                                    Viscosidad (KU)
                                </Label>
                                <DecimalInput
                                    id="viscosity"
                                    {...NUMBER_TYPES.quality}
                                    placeholder="Ej: 105,5"
                                    value={data.viscosity_ku}
                                    onValueChange={(value) =>
                                        setData('viscosity_ku', value)
                                    }
                                    disabled={isReadOnly}
                                />
                                {errors.viscosity_ku && (
                                    <p className="text-xs text-destructive">
                                        {errors.viscosity_ku}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="grinding">Molienda (HG)</Label>
                                <DecimalInput
                                    id="grinding"
                                    {...HEGMAN_LIMITS}
                                    placeholder="Ej: 7,2"
                                    value={data.grinding_hg}
                                    onValueChange={(value) =>
                                        setData('grinding_hg', value)
                                    }
                                    disabled={isReadOnly}
                                />
                                {errors.grinding_hg && (
                                    <p className="text-xs text-destructive">
                                        {errors.grinding_hg}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2 md:col-span-2">
                                <Label htmlFor="quality-solids">
                                    Sólidos (%)
                                </Label>
                                <DecimalInput
                                    id="quality-solids"
                                    {...NUMBER_TYPES.quality}
                                    placeholder="Ej: 52,4"
                                    value={data.quality_solids}
                                    onValueChange={(value) =>
                                        setData('quality_solids', value)
                                    }
                                    disabled={isReadOnly}
                                />
                                {solidsReferenceLabel && (
                                    <p className="text-xs text-muted-foreground">
                                        Referencia en ficha del producto:{' '}
                                        <span className="font-medium text-foreground">
                                            {solidsReferenceLabel}
                                        </span>
                                    </p>
                                )}
                                {errors.quality_solids && (
                                    <p className="text-xs text-destructive">
                                        {errors.quality_solids}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <Separator />

                <div className="space-y-4">
                    <Label>Consumo Real de Insumos</Label>
                    <IngredientsTable
                        rows={ingredientRows}
                        data={data}
                        setData={setData}
                        isReadOnly={isReadOnly}
                        showCosts={showCosts}
                        overconsumption={overconsumption}
                    />
                    <OverconsumptionReasons
                        overconsumption={overconsumption}
                        storedReasons={consumptionReasons}
                        codes={materialCodes}
                        data={data}
                        setData={setData}
                        errors={errors as Record<string, string | undefined>}
                        isReadOnly={isReadOnly}
                        showCosts={showCosts}
                    />
                </div>

                <LineAdjustmentsPanel
                    orderId={orderId}
                    adjustments={lineAdjustments}
                    rawMaterials={rawMaterials}
                    isCompleted={isCompleted}
                    isReadOnly={isReadOnly}
                    showCosts={showCosts}
                />

                <PackagingSection
                    orderId={orderId}
                    rows={packagingRows}
                    data={data}
                    setData={setData}
                    availableVariants={availableVariants}
                    labelMaterials={labelMaterials}
                    isReadOnly={isReadOnly}
                    showCosts={showCosts}
                    costs={packagingCosts}
                    canPrintLabels={canPrintLabels}
                />
            </CardContent>
        </Card>
    );
}
