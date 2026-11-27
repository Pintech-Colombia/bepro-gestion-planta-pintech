import { DetailItem, DetailList } from '@/components/detail-list';
import { EmptyCell } from '@/components/empty-cell';
import { FormattedNumber } from '@/components/formatted-number';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { FormNumberValue } from '@/types/production-orders';

type OpQualityReadProps = {
    viscosity: FormNumberValue | null | undefined;
    grinding: FormNumberValue | null | undefined;
    solids: FormNumberValue | null | undefined;
    qualityRanges: {
        viscosity: string | null;
        grinding: string | null;
        solids: string | null;
    };
};

/** Los indicadores de laboratorio de una orden cerrada a la edición, cada uno con el rango de la ficha del producto. */
export function OpQualityRead({
    viscosity,
    grinding,
    solids,
    qualityRanges,
}: OpQualityReadProps) {
    const indicators = [
        {
            label: 'Viscosidad (KU)',
            value: viscosity,
            range: qualityRanges.viscosity,
        },
        {
            label: 'Molienda (HG)',
            value: grinding,
            range: qualityRanges.grinding,
        },
        { label: 'Sólidos (%)', value: solids, range: qualityRanges.solids },
    ];

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
                <DetailList columns={3}>
                    {indicators.map(({ label, value, range }) => (
                        <DetailItem
                            key={label}
                            label={label}
                            value={
                                <>
                                    {value === null ||
                                    value === undefined ||
                                    value === '' ? (
                                        <EmptyCell />
                                    ) : (
                                        <FormattedNumber
                                            value={value}
                                            maxDecimals={1}
                                        />
                                    )}
                                    <span className="block text-xs text-muted-foreground">
                                        {range
                                            ? `Rango: ${range}`
                                            : 'Sin rango en la ficha'}
                                    </span>
                                </>
                            }
                        />
                    ))}
                </DetailList>
            </CardContent>
        </Card>
    );
}
