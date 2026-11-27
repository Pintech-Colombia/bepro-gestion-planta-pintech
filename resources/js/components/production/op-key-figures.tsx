import type { ReactNode } from 'react';

import { FormattedNumber } from '@/components/formatted-number';
import { Card, CardContent } from '@/components/ui/card';
import { formatPercent } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';
import { cn } from '@/lib/utils';
import type {
    FormNumberValue,
    ProductionOrderRemnant,
} from '@/types/production-orders';

type OpKeyFiguresProps = {
    realYield: number | null;
    yieldPercentage: number | null;
    packagedEquivalent: number | null;
    /** El saldo que dejó la orden, ya creado (completada). */
    remnant: ProductionOrderRemnant | null;
    /** Lo informado mientras no existe el saldo (en revisión). */
    remnantKg: FormNumberValue | null | undefined;
    remnantGallons: number;
    /** Con permiso de costos; null sin permiso o sin resumen. */
    costPerGallonWithCif: FormNumberValue | null;
};

/** Las cifras clave de la ficha de lectura (DESIGN.md §5): lo primero que se mira de una orden cerrada. */
export function OpKeyFigures({
    realYield,
    yieldPercentage,
    packagedEquivalent,
    remnant,
    remnantKg,
    remnantGallons,
    costPerGallonWithCif,
}: OpKeyFiguresProps) {
    const reportedKg = parseDecimal(remnantKg ?? null);
    // En revisión el saldo aún no existe (se crea al aprobar): lo que hay es el sobrante que informó la planta.
    const isReportedOnly = !remnant && reportedKg !== null && reportedKg > 0;
    const showCost = costPerGallonWithCif !== null;

    return (
        <div
            className={cn(
                'grid grid-cols-1 gap-4 sm:grid-cols-2',
                showCost ? 'xl:grid-cols-4' : 'xl:grid-cols-3',
            )}
        >
            <KeyFigure
                label="Rendimiento real"
                value={
                    <>
                        <FormattedNumber value={realYield} kind="quantity" />
                        {realYield !== null && ' gal'}
                    </>
                }
                detail={
                    yieldPercentage !== null
                        ? `${formatPercent(yieldPercentage, 1)} de lo proyectado`
                        : null
                }
            />
            <KeyFigure
                label="Envasado"
                value={
                    <>
                        <FormattedNumber
                            value={packagedEquivalent}
                            kind="quantity"
                        />
                        {packagedEquivalent !== null && ' gal'}
                    </>
                }
            />
            <KeyFigure
                label={isReportedOnly ? 'Sobrante informado' : 'Saldo generado'}
                value={
                    remnant ? (
                        <>
                            <FormattedNumber
                                value={remnant.original_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg
                        </>
                    ) : reportedKg !== null && reportedKg > 0 ? (
                        <>
                            <FormattedNumber
                                value={reportedKg}
                                kind="quantity"
                            />{' '}
                            kg
                        </>
                    ) : (
                        <span className="text-muted-foreground">Sin saldo</span>
                    )
                }
                detail={
                    remnant ? (
                        <>
                            ≈{' '}
                            <FormattedNumber
                                value={remnant.original_quantity_gallons}
                                kind="quantity"
                            />{' '}
                            gal · {remnant.status_label} · Disponible:{' '}
                            <FormattedNumber
                                value={remnant.available_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg
                        </>
                    ) : isReportedOnly ? (
                        <>
                            ≈{' '}
                            <FormattedNumber
                                value={remnantGallons}
                                kind="quantity"
                            />{' '}
                            gal · El saldo se crea al aprobar
                        </>
                    ) : null
                }
            />
            {showCost && (
                <KeyFigure
                    label="Costo por galón (con CIF)"
                    value={
                        <FormattedNumber
                            value={costPerGallonWithCif}
                            kind="cost"
                        />
                    }
                />
            )}
        </div>
    );
}

function KeyFigure({
    label,
    value,
    detail,
}: {
    label: string;
    value: ReactNode;
    detail?: ReactNode;
}) {
    return (
        <Card>
            <CardContent className="space-y-1">
                <p className="text-xs font-medium text-muted-foreground">
                    {label}
                </p>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                {detail && (
                    <p className="text-xs text-muted-foreground">{detail}</p>
                )}
            </CardContent>
        </Card>
    );
}
