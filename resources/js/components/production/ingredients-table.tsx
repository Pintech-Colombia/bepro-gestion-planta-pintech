import { DecimalInput } from '@/components/decimal-input';
import { FormField } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { Textarea } from '@/components/ui/textarea';
import { formatKind, formatPercent } from '@/lib/formatters';
import { pillClasses, toneClasses } from '@/lib/tones';
import type {
    ProductionOrderConsumptionReason,
    ProductionOrderFormData,
    ProductionOrderIngredientFormRow,
    ProductionOrderOverconsumption,
    ProductionOrderSetData,
} from '@/types/production-orders';

/** Tope del motivo, el mismo del servidor (`ProductionOrderConsumptionCheck::REASON_MAX_LENGTH`). */
const REASON_MAX_LENGTH = 500;

const REASON_ERROR_PREFIX = 'overconsumption_reasons.';

type IngredientsTableProps = {
    rows: ProductionOrderIngredientFormRow[];
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    isReadOnly: boolean;
    showCosts?: boolean;
    /** Las materias primas que se pasan (lo dice el servidor), por id: se marcan en cada una de sus líneas. */
    overconsumption?: ProductionOrderOverconsumption[];
};

/** El exceso como lo dice el servidor, con un decimal: «+43,9%». */
function excessLabel(row: ProductionOrderOverconsumption): string {
    return `+${formatPercent(row.excess_percentage, 1)}`;
}

export function IngredientsTable({
    rows,
    data,
    setData,
    isReadOnly,
    showCosts = true,
    overconsumption = [],
}: IngredientsTableProps) {
    const exceeding = new Map(
        overconsumption.map((row) => [row.raw_material_id, row]),
    );

    return (
        <div className="overflow-hidden rounded-md border">
            <div className="relative overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b bg-muted/50">
                        <tr>
                            <th className="w-14 p-3 text-center">Paso</th>
                            <th className="p-3 text-left">Materia Prima</th>
                            <th className="p-3 text-right">Planeado</th>
                            <th className="w-40 p-3 text-right">
                                Real Gastado
                            </th>
                            {showCosts && (
                                <>
                                    <th className="p-3 text-right">
                                        Costo Unit.
                                    </th>
                                    <th className="p-3 text-right">
                                        Costo Total
                                    </th>
                                </>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((ingredient, index) => (
                            <tr
                                key={ingredient.id}
                                className="border-b last:border-0"
                            >
                                <td className="p-3 text-center text-muted-foreground tabular-nums">
                                    {ingredient.step_order ?? '—'}
                                </td>
                                <td className="p-3 font-medium">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        {ingredient.raw_material_name}
                                        {exceeding.has(
                                            ingredient.raw_material_id,
                                        ) && (
                                            <span
                                                className={`${pillClasses} ${toneClasses.warning}`}
                                                title="Se pasó de lo planeado (todos sus pasos y ajustes)"
                                            >
                                                {excessLabel(
                                                    exceeding.get(
                                                        ingredient.raw_material_id,
                                                    )!,
                                                )}
                                            </span>
                                        )}
                                    </span>
                                </td>
                                <td className="p-3 text-right whitespace-nowrap text-muted-foreground">
                                    <FormattedNumber
                                        value={
                                            ingredient.display_quantity ??
                                            ingredient.planned_quantity
                                        }
                                        maxDecimals={4}
                                    />
                                    {ingredient.display_unit
                                        ? ` ${ingredient.display_unit}`
                                        : ingredient.raw_material_unit
                                          ? ` ${ingredient.raw_material_unit}`
                                          : ''}
                                </td>
                                <td className="p-3">
                                    <div className="flex items-center justify-end gap-1.5">
                                        <DecimalInput
                                            className="h-8 w-28 text-right"
                                            value={ingredient.actual_quantity}
                                            onValueChange={(value) => {
                                                const newIngredients = [
                                                    ...data.ingredients,
                                                ];
                                                newIngredients[index] = {
                                                    ...newIngredients[index],
                                                    actual_quantity: value,
                                                };
                                                setData(
                                                    'ingredients',
                                                    newIngredients,
                                                );
                                            }}
                                            disabled={isReadOnly}
                                        />
                                        {(ingredient.display_unit ||
                                            ingredient.raw_material_unit) && (
                                            <span className="w-6 shrink-0 text-left text-xs text-muted-foreground">
                                                {ingredient.display_unit ||
                                                    ingredient.raw_material_unit}
                                            </span>
                                        )}
                                    </div>
                                </td>
                                {showCosts && (
                                    <>
                                        <td className="p-3 text-right text-muted-foreground">
                                            <FormattedNumber
                                                value={ingredient.unit_cost}
                                                currency
                                                maxDecimals={2}
                                            />
                                        </td>
                                        <td className="p-3 text-right font-medium">
                                            <FormattedNumber
                                                value={ingredient.total_cost}
                                                currency
                                                maxDecimals={2}
                                            />
                                        </td>
                                    </>
                                )}
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td
                                    className="p-3 text-muted-foreground"
                                    colSpan={showCosts ? 6 : 4}
                                >
                                    Esta orden no tiene insumos planificados.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
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
 * El motivo de cada materia prima que se pasó de lo planeado más allá de la tolerancia. La cuenta es del servidor: se
 * pide el campo de una materia prima cuando el servidor lo pide (422 en `overconsumption_reasons.<id>`), cuando lo
 * marcó al entregar la orden o cuando ya tiene un motivo guardado. Con la orden cerrada, solo se lee.
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
    const askedIds = new Set<number>([
        ...overconsumption
            .filter((row) => row.needs_reason)
            .map((row) => row.raw_material_id),
        ...storedReasons.map((reason) => reason.raw_material_id),
        ...errorIds,
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
                                required
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
