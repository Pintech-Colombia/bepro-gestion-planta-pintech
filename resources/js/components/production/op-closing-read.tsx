import { DetailItem, DetailList } from '@/components/detail-list';
import { FormattedDate } from '@/components/formatted-date';
import { SignerPreview } from '@/components/production/op-summary-panel';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    ProductionOrder,
    QualitySignerOption,
} from '@/types/production-orders';

const TIME_FIELDS = [
    { field: 'agitation_start_time', label: 'Inicio de agitación' },
    { field: 'agitation_end_time', label: 'Fin de agitación' },
    { field: 'packaging_start_time', label: 'Inicio de envasado' },
    { field: 'packaging_end_time', label: 'Fin de envasado' },
] as const;

type OpClosingReadProps = {
    order: ProductionOrder;
    /** Para la imagen de la firma: quien firmó, si sigue entre los que pueden firmar. */
    qualitySigners: QualitySignerOption[];
};

/**
 * Cierre de una orden cerrada a la edición: responsable, observaciones, las horas que se llenaron (las vacías se omiten;
 * siguen en PDF y Excel), quién la envió, quién la aprobó y la firma del certificado.
 */
export function OpClosingRead({ order, qualitySigners }: OpClosingReadProps) {
    const hours = TIME_FIELDS.filter(({ field }) => Boolean(order[field]));
    const signerOption = qualitySigners.find(
        (signer) => signer.id === order.quality_responsible_user_id,
    );
    const signer = signerOption ?? order.quality_responsible_user ?? null;
    const isCompleted = order.status === 'completed';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Cierre</CardTitle>
            </CardHeader>
            <CardContent>
                <DetailList columns={2}>
                    <DetailItem
                        label="Responsable en planta"
                        value={order.responsible_name}
                    />
                    {/* Una orden finalizada sin pasar por revisión no tiene quién la envió ni quién la aprobó: la
                        fecha de cierre está en el encabezado. */}
                    {order.submitted_by && (
                        <DetailItem
                            label="Enviada a revisión por"
                            value={
                                order.submitted_by && (
                                    <>
                                        {order.submitted_by.name}
                                        {order.submitted_at && (
                                            <span className="block text-xs text-muted-foreground">
                                                <FormattedDate
                                                    value={order.submitted_at}
                                                    format="datetime"
                                                />
                                            </span>
                                        )}
                                    </>
                                )
                            }
                        />
                    )}
                    {isCompleted && order.reviewed_by && (
                        <DetailItem
                            label="Aprobada por"
                            value={
                                order.reviewed_by && (
                                    <>
                                        {order.reviewed_by.name}
                                        {order.reviewed_at && (
                                            <span className="block text-xs text-muted-foreground">
                                                <FormattedDate
                                                    value={order.reviewed_at}
                                                    format="datetime"
                                                />
                                            </span>
                                        )}
                                    </>
                                )
                            }
                        />
                    )}
                    {/* En revisión aún nadie firma: se elige al aprobar. */}
                    {isCompleted && (
                        <DetailItem
                            label="Firma del certificado"
                            value={
                                signer &&
                                (signerOption ? (
                                    <SignerPreview signer={signerOption} />
                                ) : (
                                    <>
                                        {signer.name}
                                        <span className="block text-xs text-muted-foreground">
                                            {signer.job_title}
                                        </span>
                                    </>
                                ))
                            }
                        />
                    )}
                    {hours.map(({ field, label }) => (
                        <DetailItem
                            key={field}
                            label={label}
                            value={
                                <FormattedDate
                                    value={order[field]}
                                    format="datetime"
                                />
                            }
                        />
                    ))}
                    <DetailItem
                        label="Observaciones"
                        value={order.notes}
                        span="full"
                        valueClassName="whitespace-pre-wrap"
                    />
                </DetailList>
            </CardContent>
        </Card>
    );
}
