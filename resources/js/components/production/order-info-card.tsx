import type { ReactNode } from 'react';

import { FormattedNumber } from '@/components/formatted-number';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { formatPercent } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type {
    FormNumberValue,
    ProductionOrder,
    ProductionOrderCostSummary,
} from '@/types/production-orders';

type OrderInfoCardProps = {
    order: ProductionOrder;
    /** Lo envasado, en galones equivalentes; null mientras la orden está pendiente. */
    packagedEquivalent: number | null;
    /** Kilos pesados del sobrante; vacío si no hubo. */
    remnantKg?: FormNumberValue | null;
    /** Lo envasado más el sobrante: no se digita. */
    realYield: number | null;
    yieldPercentage: number | null;
    remnantQuantityGallons?: FormNumberValue;
    /** Resumen de costos del servidor (ProductionOrderCostSummary); null si la orden está cancelada. */
    costSummary?: ProductionOrderCostSummary | null;
    showCosts?: boolean;
};

/**
 * Tarjeta de la OP en tres bloques: cantidades · costo (estimado o real) · comparación con la lista de hoy. Los costos
 * llegan calculados del servidor; aquí solo se pintan.
 */
export function OrderInfoCard({
    order,
    packagedEquivalent,
    remnantKg,
    realYield,
    yieldPercentage,
    remnantQuantityGallons,
    costSummary,
    showCosts = true,
}: OrderInfoCardProps) {
    const hasRemnant =
        remnantQuantityGallons !== undefined &&
        remnantQuantityGallons !== '' &&
        Number(remnantQuantityGallons) > 0;

    return (
        <Card className="bg-muted/40">
            <CardContent className="space-y-2 p-4 text-xs">
                <InfoRow label="Fórmula">
                    v{order.formula?.version}
                    {order.formula?.name && ` · ${order.formula.name}`}
                </InfoRow>
                <InfoRow label="Fecha plan">{order.planned_date}</InfoRow>
                <InfoRow label="Bodega">{order.warehouse?.name}</InfoRow>
                <Separator />
                <InfoRow label="Envasado">
                    <FormattedNumber
                        value={packagedEquivalent}
                        maxDecimals={2}
                        trimTrailingZeros
                    />
                    {packagedEquivalent !== null && ' gal'}
                </InfoRow>
                {hasRemnant && (
                    <InfoRow
                        label={
                            <>
                                Sobrante
                                {remnantKg !== null &&
                                    remnantKg !== undefined &&
                                    remnantKg !== '' && (
                                        <>
                                            {' ('}
                                            <FormattedNumber
                                                value={remnantKg}
                                                kind="quantity"
                                            />{' '}
                                            kg)
                                        </>
                                    )}
                            </>
                        }
                    >
                        <FormattedNumber
                            value={remnantQuantityGallons}
                            maxDecimals={2}
                            trimTrailingZeros
                        />{' '}
                        gal
                    </InfoRow>
                )}
                <InfoRow label="Rendimiento real" strong>
                    <FormattedNumber
                        value={realYield}
                        maxDecimals={2}
                        trimTrailingZeros
                    />
                    {realYield !== null && ' gal'}
                    {yieldPercentage !== null && (
                        <span className="font-normal text-muted-foreground">
                            {' ('}
                            {formatPercent(yieldPercentage, 1)})
                        </span>
                    )}
                </InfoRow>
                {showCosts && costSummary && (
                    <>
                        <Separator />
                        <CostBlock summary={costSummary} />
                        <Separator />
                        <ComparisonBlock summary={costSummary} />
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/** Bloque 2: lo que costó (o costaría) la orden. Sin CIF salvo donde se dice. */
function CostBlock({ summary }: { summary: ProductionOrderCostSummary }) {
    const isActual = summary.basis === 'actual';

    return (
        <div className="space-y-2">
            <BlockTitle>
                {isActual ? 'Costo real' : 'Costo estimado'}
            </BlockTitle>
            {summary.cif_estimated && (
                <p className="text-muted-foreground">
                    CIF estimado: la orden no guardó el CIF del día en que se
                    completó; se calculó después.
                </p>
            )}
            <InfoRow label="Granel total" strong>
                <Money value={summary.bulk.total} />
            </InfoRow>
            <InfoRow label="Materias primas" indent>
                <Money value={summary.bulk.raw_materials} />
            </InfoRow>
            <InfoRow label="Ajustes de línea" indent>
                <Money value={summary.bulk.line_adjustments} />
            </InfoRow>
            <InfoRow label="Saldos usados" indent>
                <Money value={summary.bulk.remnants_used} />
            </InfoRow>
            <InfoRow label="Lotes de PT usados" indent>
                <Money value={summary.bulk.products_used} />
            </InfoRow>
            <InfoRow label="Costo por galón (sin CIF)">
                <Money value={summary.cost_per_gallon} />
            </InfoRow>
            <InfoRow label="Costo por galón (con CIF)">
                <Money value={summary.cost_per_gallon_with_cif} />
            </InfoRow>
            <InfoRow label="Costo por kg (sin CIF)">
                <Money
                    value={summary.cost_per_kg}
                    emptyValue="Sin peso por galón"
                />
            </InfoRow>
            <InfoRow label="Costo de lo envasado" strong>
                <Money value={summary.packaged.total} />
            </InfoRow>
            <InfoRow label="Granel de lo envasado" indent>
                <Money value={summary.packaged.bulk} />
            </InfoRow>
            <InfoRow label="Envases y etiquetas" indent>
                <Money value={summary.packaged.packaging_materials} />
            </InfoRow>
            <InfoRow label="Costo con CIF (envasado)" strong>
                <Money value={summary.packaged.total_with_cif} />
            </InfoRow>
            {summary.remnant && (
                <>
                    <InfoRow label="Saldo con CIF">
                        <Money
                            value={summary.remnant.cost_with_cif}
                            emptyValue="Sin costo"
                        />
                    </InfoRow>
                    <InfoRow label="Total con saldo (con CIF)" strong>
                        <Money
                            value={summary.remnant.total_with_remnant}
                            emptyValue="Sin costo"
                        />
                    </InfoRow>
                </>
            )}
        </div>
    );
}

/** Bloque 3: contra la lista de precios de hoy (teórico por galón, precio interno y margen por presentación). */
function ComparisonBlock({ summary }: { summary: ProductionOrderCostSummary }) {
    const { comparison } = summary;
    const hasMargin = comparison.list_margin_percentage !== null;

    return (
        <div className="space-y-2">
            <BlockTitle>Comparación con la lista de hoy</BlockTitle>
            <InfoRow label="Teórico por galón (referencia)">
                <Money
                    value={comparison.reference_cost_per_gallon}
                    emptyValue="Sin costo teórico"
                />
            </InfoRow>
            {comparison.reference_difference_percentage !== null && (
                <InfoRow label="Real contra teórico" indent>
                    <Difference
                        value={comparison.reference_difference_percentage}
                    />
                </InfoRow>
            )}
            {comparison.reference_cost_per_gallon !== null &&
                !comparison.uses_reference_formula && (
                    <p className="text-muted-foreground">
                        La orden usó otra fórmula que la de referencia de hoy.
                    </p>
                )}
            {!hasMargin && (
                <p className="text-muted-foreground">Sin margen en la lista.</p>
            )}
            {comparison.presentations.map((presentation) => (
                <div key={presentation.id} className="space-y-1 pt-1">
                    <p className="font-medium text-foreground">
                        {presentation.presentation_label ?? 'Presentación'}
                    </p>
                    <InfoRow label="Costo con CIF" indent>
                        <Money value={presentation.unit_cost_with_cif} />
                    </InfoRow>
                    <InfoRow label="Precio interno" indent>
                        <Money
                            value={presentation.list_price}
                            emptyValue="Sin precio en la lista"
                        />
                    </InfoRow>
                    {presentation.list_difference_percentage !== null && (
                        <InfoRow label="Costo contra precio" indent>
                            <Difference
                                value={presentation.list_difference_percentage}
                            />
                        </InfoRow>
                    )}
                    {hasMargin &&
                        presentation.real_margin_percentage !== null && (
                            <InfoRow label="Margen real" indent>
                                <FormattedNumber
                                    value={presentation.real_margin_percentage}
                                    kind="percent"
                                />
                                <span className="font-normal text-muted-foreground">
                                    {' (lista '}
                                    <FormattedNumber
                                        value={
                                            comparison.list_margin_percentage
                                        }
                                        kind="percent"
                                    />
                                    )
                                </span>
                            </InfoRow>
                        )}
                </div>
            ))}
        </div>
    );
}

function BlockTitle({ children }: { children: ReactNode }) {
    return <p className="text-sm font-medium text-foreground">{children}</p>;
}

function InfoRow({
    label,
    children,
    strong = false,
    indent = false,
}: {
    label: ReactNode;
    children: ReactNode;
    strong?: boolean;
    indent?: boolean;
}) {
    return (
        <div className={cn('flex justify-between gap-3', indent && 'pl-3')}>
            <span className="text-muted-foreground">{label}</span>
            <span
                className={cn(
                    'text-right tabular-nums',
                    strong ? 'font-semibold' : 'font-medium',
                )}
            >
                {children}
            </span>
        </div>
    );
}

function Money({
    value,
    emptyValue,
}: {
    value: string | null;
    emptyValue?: string;
}) {
    return (
        <FormattedNumber value={value} kind="cost" emptyValue={emptyValue} />
    );
}

/** Diferencia en %, con signo: positiva (costó más que la referencia) en tono de advertencia. */
function Difference({ value }: { value: string }) {
    const isAbove = Number(value) > 0;

    return (
        <span className={cn(isAbove && 'text-warning-text')}>
            {isAbove && '+'}
            <FormattedNumber value={value} kind="percent" />
        </span>
    );
}
