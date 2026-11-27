import { DecimalInput } from '@/components/decimal-input';
import { FormField } from '@/components/form-field';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { HEGMAN_LIMITS, NUMBER_TYPES } from '@/lib/number-types';
import type {
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderSetData,
} from '@/types/production-orders';

type OpQualitySectionProps = {
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: ProductionOrderErrors;
    isReadOnly: boolean;
    /** El rango de cada indicador en la ficha del producto («95 – 105»), o null si no tiene. */
    qualityRanges: {
        viscosity: string | null;
        grinding: string | null;
        solids: string | null;
    };
};

/** Sección 3 de la OP: los indicadores de laboratorio, en una fila; ninguno es obligatorio. */
export function OpQualitySection({
    data,
    setData,
    errors,
    isReadOnly,
    qualityRanges,
}: OpQualitySectionProps) {
    const rangeHelp = (range: string | null) =>
        range ? `Rango: ${range}` : 'Sin rango en la ficha';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Agitación y calidad</CardTitle>
                <CardDescription>
                    Van al certificado de calidad del lote; una prueba vacía no
                    sale en el certificado.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <FormField
                        id="viscosity_ku"
                        label="Viscosidad (KU)"
                        help={rangeHelp(qualityRanges.viscosity)}
                        error={errors.viscosity_ku}
                    >
                        <DecimalInput
                            {...NUMBER_TYPES.quality}
                            value={data.viscosity_ku}
                            onValueChange={(value) =>
                                setData('viscosity_ku', value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                    <FormField
                        id="grinding_hg"
                        label="Molienda (HG)"
                        help={rangeHelp(qualityRanges.grinding)}
                        error={errors.grinding_hg}
                    >
                        <DecimalInput
                            {...HEGMAN_LIMITS}
                            value={data.grinding_hg}
                            onValueChange={(value) =>
                                setData('grinding_hg', value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                    <FormField
                        id="quality_solids"
                        label="Sólidos (%)"
                        help={rangeHelp(qualityRanges.solids)}
                        error={errors.quality_solids}
                    >
                        <DecimalInput
                            {...NUMBER_TYPES.quality}
                            value={data.quality_solids}
                            onValueChange={(value) =>
                                setData('quality_solids', value)
                            }
                            disabled={isReadOnly}
                        />
                    </FormField>
                </div>
            </CardContent>
        </Card>
    );
}
