import type { ReactNode } from 'react';

import AlertError from '@/components/alert-error';
import { FormField } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import {
    InfoRow,
    Money,
    OpCostBreakdownSheet,
} from '@/components/production/op-cost-breakdown-sheet';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { formErrorMessages } from '@/lib/form-errors';
import { formatPercent } from '@/lib/formatters';
import type {
    FormNumberValue,
    ProductionOrder,
    ProductionOrderCostSummary,
    ProductionOrderErrors,
    ProductionOrderFormData,
    ProductionOrderSetData,
    QualitySignerOption,
} from '@/types/production-orders';

type OpSummaryPanelProps = {
    order: ProductionOrder;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: ProductionOrderErrors;
    /** Lo envasado, en galones equivalentes; null mientras la orden está pendiente. */
    packagedEquivalent: number | null;
    /** Kilos pesados del sobrante; vacío si no hubo. */
    remnantKg?: FormNumberValue | null;
    remnantGallons: number;
    /** Lo envasado más el sobrante: no se digita. */
    realYield: number | null;
    yieldPercentage: number | null;
    /** Resumen de costos del servidor; null si la orden está cancelada. */
    costSummary: ProductionOrderCostSummary | null;
    showCosts: boolean;
    isReadOnly: boolean;
    /** Pendiente y con permiso: el aviso «Orden planificada» con «Iniciar producción». */
    showStart: boolean;
    isStartingProduction: boolean;
    onStart: () => void;
    showSubmit: boolean;
    submitLabel: string;
    processing: boolean;
    processingLabel: string;
    hasOrderData: boolean;
    showReject: boolean;
    onReject: () => void;
    qualitySigners: QualitySignerOption[];
    /** Quien aprueba elige la firma del certificado, justo encima de «Aprobar y cerrar». */
    showQualitySigner: boolean;
    /** Lo que va arriba del resumen (el QR de una orden completada). */
    children?: ReactNode;
};

/**
 * Columna derecha de la OP: el resumen (rendimiento y, con permiso, tres cifras de costo con el desglose aparte) y las
 * acciones del estado (iniciar, enviar, aprobar con firma, devolver), con los errores del servidor junto al botón.
 */
export function OpSummaryPanel({
    order,
    data,
    setData,
    errors,
    packagedEquivalent,
    remnantKg,
    remnantGallons,
    realYield,
    yieldPercentage,
    costSummary,
    showCosts,
    isReadOnly,
    showStart,
    isStartingProduction,
    onStart,
    showSubmit,
    submitLabel,
    processing,
    processingLabel,
    hasOrderData,
    showReject,
    onReject,
    qualitySigners,
    showQualitySigner,
    children,
}: OpSummaryPanelProps) {
    const selectedSigner = qualitySigners.find(
        (signer) => signer.id === data.quality_responsible_user_id,
    );
    // Todos los errores del servidor junto al botón, también los que no tienen un campo visible (OPL-11): la falta de
    // stock de un ajuste, una fila, o que la orden cambió mientras estaba abierta.
    const serverErrors = formErrorMessages(errors);
    const hasRemnant = remnantGallons > 0;
    // En la ficha de lectura la firma va en el Cierre, con quién envió y quién aprobó.
    const showSignerPicker = showQualitySigner && !isReadOnly;

    return (
        <div className="space-y-6">
            {children}

            {showStart && (
                <Alert variant="info">
                    <AlertTitle>Orden planificada</AlertTitle>
                    <AlertDescription className="space-y-3">
                        <p>
                            Confirma el inicio cuando la mezcla comience en
                            planta para registrar los datos y enviarla a
                            revisión.
                        </p>
                        <Button
                            type="button"
                            onClick={onStart}
                            disabled={isStartingProduction}
                        >
                            {isStartingProduction
                                ? 'Iniciando...'
                                : 'Iniciar producción'}
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>Resumen</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                    <InfoRow label="Proyectado">
                        <FormattedNumber
                            value={order.quantity}
                            kind="quantity"
                        />{' '}
                        gal
                    </InfoRow>
                    <InfoRow label="Envasado">
                        <FormattedNumber
                            value={packagedEquivalent}
                            kind="quantity"
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
                                value={remnantGallons}
                                kind="quantity"
                            />{' '}
                            gal
                        </InfoRow>
                    )}
                    <InfoRow label="Rendimiento real" strong>
                        <FormattedNumber value={realYield} kind="quantity" />
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
                            <InfoRow label="Costo granel">
                                <Money value={costSummary.bulk.total} />
                            </InfoRow>
                            <InfoRow label="Costo por galón (sin CIF)">
                                <Money value={costSummary.cost_per_gallon} />
                            </InfoRow>
                            <InfoRow label="Costo por galón (con CIF)" strong>
                                <Money
                                    value={costSummary.cost_per_gallon_with_cif}
                                />
                            </InfoRow>
                            <OpCostBreakdownSheet summary={costSummary} />
                        </>
                    )}
                </CardContent>
            </Card>

            {(showSubmit || showReject) && (
                <Card>
                    <CardContent className="space-y-4">
                        {showSignerPicker && (
                            <FormField
                                id="quality_responsible_user_id"
                                label="Firma del certificado"
                                error={errors.quality_responsible_user_id}
                                required
                            >
                                {(control) => (
                                    <Select
                                        value={
                                            data.quality_responsible_user_id
                                                ? String(
                                                      data.quality_responsible_user_id,
                                                  )
                                                : ''
                                        }
                                        onValueChange={(value) =>
                                            setData(
                                                'quality_responsible_user_id',
                                                Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger {...control}>
                                            <SelectValue placeholder="Quién firma el certificado..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {qualitySigners.map((signer) => (
                                                <SelectItem
                                                    key={signer.id}
                                                    value={String(signer.id)}
                                                >
                                                    {signer.name} —{' '}
                                                    {signer.job_title}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                            </FormField>
                        )}

                        {showSignerPicker && selectedSigner && (
                            <SignerPreview signer={selectedSigner} />
                        )}

                        {!isReadOnly && serverErrors.length > 0 && (
                            <AlertError
                                title="Revisa estos datos y vuelve a confirmar"
                                errors={serverErrors}
                            />
                        )}

                        {showSubmit && (
                            <Button
                                type="submit"
                                className="w-full"
                                size="lg"
                                disabled={processing || !hasOrderData}
                            >
                                {processing ? processingLabel : submitLabel}
                            </Button>
                        )}

                        {showReject && (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full"
                                onClick={onReject}
                                disabled={processing}
                            >
                                Devolver a planta
                            </Button>
                        )}

                        {!isReadOnly && !hasOrderData && (
                            <p className="text-xs text-destructive">
                                La orden no tiene detalle de insumos ni plan de
                                empaque. Revise la fórmula y vuelva a crearla.
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

/** La firma elegida: imagen, nombre y cargo. También la usa el Cierre de la ficha de lectura. */
export function SignerPreview({ signer }: { signer: QualitySignerOption }) {
    return (
        <div className="flex items-center gap-3 rounded-md border bg-muted/30 p-2">
            {signer.signature_url && (
                <img
                    src={signer.signature_url}
                    alt="Firma"
                    className="h-8 max-w-30 object-contain"
                />
            )}
            <div className="text-xs">
                <p className="font-medium">{signer.name}</p>
                <p className="text-muted-foreground">{signer.job_title}</p>
            </div>
        </div>
    );
}
