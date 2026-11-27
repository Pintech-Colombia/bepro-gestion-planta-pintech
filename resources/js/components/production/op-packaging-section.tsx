import { DecimalInput } from '@/components/decimal-input';
import { FormField } from '@/components/form-field';
import { PackagingSection } from '@/components/production/packaging-section';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatQuantity } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';
import { remnantGallonsForDisplay } from '@/lib/remnant-quantity';
import type {
    LabelMaterialOption,
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderPackagingCost,
    ProductionOrderPackagingFormRow,
    ProductionOrderSetData,
    VariantOption,
} from '@/types/production-orders';

type OpPackagingSectionProps = {
    orderId: number;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: ProductionOrderErrors;
    packagingRows: ProductionOrderPackagingFormRow[];
    /** Costo de cada presentación por id del plan, del resumen de costos. */
    packagingCosts?: ReadonlyMap<number, ProductionOrderPackagingCost>;
    labelMaterials: LabelMaterialOption[];
    availableVariants: VariantOption[];
    isReadOnly: boolean;
    showCosts: boolean;
    canPrintLabels: boolean;
};

/**
 * Sección 4 de la OP: lo envasado por presentación y, debajo, el sobrante sin envasar con el peso por galón (que lo
 * pasa a galones y es obligatorio al aprobar) y el derrame.
 */
export function OpPackagingSection({
    orderId,
    data,
    setData,
    errors,
    packagingRows,
    packagingCosts,
    labelMaterials,
    availableVariants,
    isReadOnly,
    showCosts,
    canPrintLabels,
}: OpPackagingSectionProps) {
    const remnantKilos = parseDecimal(data.remnant_quantity_kg);
    const remnantGallons = remnantGallonsForDisplay(
        data.remnant_quantity_kg,
        data.density_kg_per_gallon,
    );
    const remnantHelp =
        remnantGallons !== null
            ? `≈ ${formatQuantity(remnantGallons, { maxDecimals: 2, trimTrailingZeros: true })} gal`
            : remnantKilos !== null && remnantKilos > 0
              ? 'Digita el peso por galón para ver los galones.'
              : 'Producto sin envasar, pesado sin el envase.';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Envasado, sobrante y derrame</CardTitle>
                <CardDescription>
                    Unidades envasadas por presentación. Lo que sobró sin
                    envasar queda como saldo para otras órdenes.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
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
                    errors={errors}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <FormField
                        id="remnant_quantity_kg"
                        label="Sobrante (kg)"
                        help={remnantHelp}
                        error={errors.remnant_quantity_kg}
                    >
                        <DecimalInput
                            placeholder="0"
                            value={data.remnant_quantity_kg}
                            onValueChange={(value) =>
                                setData('remnant_quantity_kg', value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                    <FormField
                        id="density_kg_per_gallon"
                        label="Peso por galón (kg/gal)"
                        help="Obligatorio al aprobar."
                        error={errors.density_kg_per_gallon}
                        required
                    >
                        <DecimalInput
                            maxIntegerDigits={6}
                            value={data.density_kg_per_gallon}
                            onValueChange={(value) =>
                                setData('density_kg_per_gallon', value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                    <FormField
                        id="spillage_quantity"
                        label="Derrame (kg)"
                        error={errors.spillage_quantity}
                    >
                        <DecimalInput
                            value={data.spillage_quantity}
                            onValueChange={(value) =>
                                setData('spillage_quantity', value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                    <FormField
                        id="remnant_notes"
                        label="Nota del sobrante"
                        error={errors.remnant_notes}
                        className="sm:col-span-3"
                    >
                        <Input
                            placeholder="Ej: Tarro azul al fondo..."
                            value={data.remnant_notes}
                            onChange={(event) =>
                                setData('remnant_notes', event.target.value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                </div>
            </CardContent>
        </Card>
    );
}
