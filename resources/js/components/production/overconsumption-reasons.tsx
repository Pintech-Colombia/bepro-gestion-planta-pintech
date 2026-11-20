import { FormField } from '@/components/form-field';
import { Textarea } from '@/components/ui/textarea';
import { formatKind, formatPercent } from '@/lib/formatters';
import type {
    ProductionOrderConsumptionReason,
    ProductionOrderFormData,
    ProductionOrderOverconsumption,
    ProductionOrderSetData,
} from '@/types/production-orders';

/** Tope del motivo, el mismo del servidor (`ProductionOrderConsumptionCheck::REASON_MAX_LENGTH`). */
const REASON_MAX_LENGTH = 500;

const REASON_ERROR_PREFIX = 'overconsumption_reasons.';

/** El exceso como lo dice el servidor, con un decimal: «+43,9%». */
export function excessLabel(row: ProductionOrderOverconsumption): string {
    return `+${formatPercent(row.excess_percentage, 1)}`;
}

type OverconsumptionReasonsProps = {
    /** Las materias primas que se pasan, con lo guardado (en revisión y completada). */
    overconsumption: ProductionOrderOverconsumption[];
    /** Los motivos guardados: si la orden volvió de revisión, se pueden editar. */
    storedReasons: ProductionOrderConsumptionReason[];
    /** Código de cada materia prima de la orden, por id, en el orden de sus líneas. */
    codes: ReadonlyMap<number, string>;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: Record<string, string | undefined>;
    isReadOnly: boolean;
    showCosts?: boolean;
};

/**
 * El motivo de cada materia prima que se pasó de lo planeado más allá de la tolerancia. La cuenta es del servidor: el
 * campo de una materia prima sale cuando el servidor lo pide (422 en `overconsumption_reasons.<id>`), cuando lo marcó al
 * entregar la orden, cuando ya tiene un motivo guardado o cuando ya se escribió algo en el formulario. Con la orden
 * cerrada, solo se lee.
 */
export function OverconsumptionReasons({
    overconsumption,
    storedReasons,
    codes,
    data,
    setData,
    errors,
    isReadOnly,
    showCosts = true,
}: OverconsumptionReasonsProps) {
    const byId = new Map(
        overconsumption.map((row) => [row.raw_material_id, row]),
    );
    const errorIds = Object.keys(errors)
        .filter((key) => key.startsWith(REASON_ERROR_PREFIX) && errors[key])
        .map((key) => Number(key.slice(REASON_ERROR_PREFIX.length)));
    // Obligatorio solo lo que el servidor pide: lo marcado al entregar la orden o el 422 de este envío.
    const requiredIds = new Set<number>([
        ...overconsumption
            .filter((row) => row.needs_reason)
            .map((row) => row.raw_material_id),
        ...errorIds,
    ]);
    // Visible también lo guardado y lo ya escrito en el formulario: si el siguiente envío falla por otra causa (stock,
    // otro campo), el 422 del motivo se va pero el texto sigue y se tiene que poder ver y corregir.
    const askedIds = new Set<number>([
        ...requiredIds,
        ...storedReasons.map((reason) => reason.raw_material_id),
        ...Object.keys(data.overconsumption_reasons).map(Number),
    ]);
    // En el orden de las líneas de la orden; un id que no está en ellas va al final.
    const order = [...codes.keys()];
    const position = (id: number) => {
        const index = order.indexOf(id);

        return index === -1 ? order.length : index;
    };
    const fieldIds = [...askedIds].sort((a, b) => position(a) - position(b));
    // Las que se pasan solo por ajustes de línea: se informan, sin campo.
    const adjustmentOnly = overconsumption.filter(
        (row) => !row.needs_reason && !askedIds.has(row.raw_material_id),
    );

    if (
        isReadOnly
            ? overconsumption.length === 0
            : fieldIds.length === 0 && adjustmentOnly.length === 0
    ) {
        return null;
    }

    const summary = (row: ProductionOrderOverconsumption) =>
        `${formatKind(row.consumed, 'quantity')}${row.unit ? ` ${row.unit}` : ''} de ${formatKind(row.planned, 'quantity')} planeados (${excessLabel(row)}, tolerancia ${formatPercent(row.tolerance_percentage)})`;

    const extraCost = (row: ProductionOrderOverconsumption) =>
        showCosts && row.extra_cost != null
            ? ` · Costo del exceso: ${formatKind(row.extra_cost, 'cost')}`
            : '';

    return (
        <div className="space-y-3 rounded-md border p-4">
            <div>
                <p className="text-sm font-medium">
                    Consumo por encima de lo planeado
                </p>
                <p className="text-xs text-muted-foreground">
                    Por materia prima, sumando todos sus pasos y ajustes de
                    línea.
                </p>
            </div>

            {isReadOnly ? (
                <ul className="space-y-3">
                    {overconsumption.map((row) => (
                        <li key={row.raw_material_id} className="text-sm">
                            <p className="font-medium">
                                {row.code ?? codes.get(row.raw_material_id)}
                            </p>
                            <p className="text-muted-foreground">
                                {summary(row)}
                                {extraCost(row)}
                            </p>
                            <p className="mt-1 break-words">
                                {row.reason ??
                                    (row.needs_reason
                                        ? 'Sin motivo registrado.'
                                        : 'El exceso viene de ajustes de línea, que ya tienen su motivo.')}
                            </p>
                        </li>
                    ))}
                </ul>
            ) : (
                <div className="grid gap-4">
                    {fieldIds.map((id) => {
                        const row = byId.get(id);
                        const key = `${REASON_ERROR_PREFIX}${id}`;

                        return (
                            <FormField
                                key={id}
                                id={key}
                                label={`Motivo · ${row?.code ?? codes.get(id) ?? `MP #${id}`}`}
                                help={
                                    row
                                        ? `${summary(row)}${extraCost(row)}`
                                        : undefined
                                }
                                error={errors[key]}
                                required={requiredIds.has(id)}
                            >
                                <Textarea
                                    maxLength={REASON_MAX_LENGTH}
                                    rows={2}
                                    placeholder="¿Por qué se usó más de lo planeado?"
                                    value={
                                        data.overconsumption_reasons[id] ?? ''
                                    }
                                    onChange={(event) =>
                                        setData('overconsumption_reasons', {
                                            ...data.overconsumption_reasons,
                                            [id]: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        );
                    })}
                    {adjustmentOnly.map((row) => (
                        <p
                            key={row.raw_material_id}
                            className="text-sm text-muted-foreground"
                        >
                            {row.code}: {summary(row)}. El exceso viene de
                            ajustes de línea, que ya tienen su motivo.
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}
