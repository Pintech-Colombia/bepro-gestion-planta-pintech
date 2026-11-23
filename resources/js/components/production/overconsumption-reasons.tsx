import { FormField } from '@/components/form-field';
import { Textarea } from '@/components/ui/textarea';
import { formatKind, formatPercent } from '@/lib/formatters';
import type {
    ProductionOrderFormData,
    ProductionOrderSetData,
} from '@/types/production-orders';

/** Tope del motivo, el mismo del servidor (`ProductionOrderConsumptionCheck::REASON_MAX_LENGTH`). */
const REASON_MAX_LENGTH = 500;

/** El exceso como lo dice el servidor, con un decimal: «+43,9%». */
export function excessLabel(row: { excess_percentage: string }): string {
    return `+${formatPercent(row.excess_percentage, 1)}`;
}

/** Una materia prima o un producto que se pasó de lo planeado, con lo que muestra el bloque de motivos. */
export type OverconsumptionReasonRow = {
    id: number;
    label: string | null;
    unit: string | null;
    planned: string;
    consumed: string;
    excess_percentage: string;
    tolerance_percentage: string;
    /** false: el exceso viene de ajustes de línea, que ya traen su motivo (solo materias primas). */
    needs_reason: boolean;
    reason: string | null;
    extra_cost?: string | null;
};

/** El campo del formulario y de los errores: los motivos de materia prima y los de producto van por separado. */
type ReasonField =
    'overconsumption_reasons' | 'product_overconsumption_reasons';

type OverconsumptionReasonsProps = {
    field: ReasonField;
    title: string;
    description: string;
    /** Los que se pasan, con lo guardado (en revisión y completada). */
    rows: OverconsumptionReasonRow[];
    /** Los ids con motivo guardado: si la orden volvió de revisión, se pueden editar. */
    storedIds: number[];
    /** El nombre de cada uno por id, en el orden en que aparecen en la orden. */
    labels: ReadonlyMap<number, string>;
    /** Lo que dice el campo de alguien que no está en `labels` («MP #12», «PT #3»). */
    fallbackLabel: (id: number) => string;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    errors: Record<string, string | undefined>;
    isReadOnly: boolean;
    showCosts?: boolean;
};

/**
 * El motivo de cada materia prima, o de cada producto terminado de la fórmula, que se pasó de lo planeado más allá de la
 * tolerancia. La cuenta es del servidor: el campo sale cuando el servidor lo pide (422 en `<campo>.<id>`), cuando lo
 * marcó al entregar la orden, cuando ya tiene un motivo guardado o cuando ya se escribió algo en el formulario. Con la
 * orden cerrada, solo se lee.
 */
export function OverconsumptionReasons({
    field,
    title,
    description,
    rows,
    storedIds,
    labels,
    fallbackLabel,
    data,
    setData,
    errors,
    isReadOnly,
    showCosts = true,
}: OverconsumptionReasonsProps) {
    const prefix = `${field}.`;
    const typed = data[field];
    const byId = new Map(rows.map((row) => [row.id, row]));
    const errorIds = Object.keys(errors)
        .filter((key) => key.startsWith(prefix) && errors[key])
        .map((key) => Number(key.slice(prefix.length)));
    // Obligatorio solo lo que el servidor pide: lo marcado al entregar la orden o el 422 de este envío.
    const requiredIds = new Set<number>([
        ...rows.filter((row) => row.needs_reason).map((row) => row.id),
        ...errorIds,
    ]);
    // Visible también lo guardado y lo ya escrito en el formulario: si el siguiente envío falla por otra causa (stock,
    // otro campo), el 422 del motivo se va pero el texto sigue y se tiene que poder ver y corregir.
    const askedIds = new Set<number>([
        ...requiredIds,
        ...storedIds,
        ...Object.keys(typed).map(Number),
    ]);
    // En el orden de la orden; un id que no está en ella va al final.
    const order = [...labels.keys()];
    const position = (id: number) => {
        const index = order.indexOf(id);

        return index === -1 ? order.length : index;
    };
    const fieldIds = [...askedIds].sort((a, b) => position(a) - position(b));
    // Las que se pasan solo por ajustes de línea: se informan, sin campo.
    const adjustmentOnly = rows.filter(
        (row) => !row.needs_reason && !askedIds.has(row.id),
    );
    const labelOf = (id: number) =>
        byId.get(id)?.label ?? labels.get(id) ?? fallbackLabel(id);

    if (
        isReadOnly
            ? rows.length === 0
            : fieldIds.length === 0 && adjustmentOnly.length === 0
    ) {
        return null;
    }

    const summary = (row: OverconsumptionReasonRow) =>
        `${formatKind(row.consumed, 'quantity')}${row.unit ? ` ${row.unit}` : ''} de ${formatKind(row.planned, 'quantity')} planeados (${excessLabel(row)}, tolerancia ${formatPercent(row.tolerance_percentage)})`;

    const extraCost = (row: OverconsumptionReasonRow) =>
        showCosts && row.extra_cost != null
            ? ` · Costo del exceso: ${formatKind(row.extra_cost, 'cost')}`
            : '';

    return (
        <div className="space-y-3 rounded-md border p-4">
            <div>
                <p className="text-sm font-medium">{title}</p>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>

            {isReadOnly ? (
                <ul className="space-y-3">
                    {rows.map((row) => (
                        <li key={row.id} className="text-sm">
                            <p className="font-medium">{labelOf(row.id)}</p>
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
                        const key = `${prefix}${id}`;

                        return (
                            <FormField
                                key={id}
                                id={key}
                                label={`Motivo · ${labelOf(id)}`}
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
                                    value={typed[id] ?? ''}
                                    onChange={(event) =>
                                        setData(field, {
                                            ...typed,
                                            [id]: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        );
                    })}
                    {adjustmentOnly.map((row) => (
                        <p
                            key={row.id}
                            className="text-sm text-muted-foreground"
                        >
                            {labelOf(row.id)}: {summary(row)}. El exceso viene
                            de ajustes de línea, que ya tienen su motivo.
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}
