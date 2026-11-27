import { router } from '@inertiajs/react';
import { useState } from 'react';

import { update as updateBatchConsumption } from '@/actions/App/Http/Controllers/Production/BatchConsumptionController';
import { DecimalInput } from '@/components/decimal-input';
import { FormField } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { IntegerInput } from '@/components/integer-input';
import { preventSubmitOnEnter } from '@/components/production/product-source-picker';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { batchUnitsSuggestion, batchUsageSummary } from '@/lib/batch-usage';
import { formatKind } from '@/lib/formatters';
import type {
    ProductionOrderBatchConsumption,
    ProductionOrderBatchUsageRow,
    ProductionOrderProductLineUsageRow,
} from '@/types/production-orders';

/** Cambiar las unidades de un lote: se guarda al momento, como agregar o quitar. */
export function BatchUnitsEditor({
    orderId,
    source,
}: {
    orderId: number;
    source: ProductionOrderBatchConsumption;
}) {
    const [units, setUnits] = useState(String(source.units));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const isDirty = units !== String(source.units);

    const save = () => {
        if (!isDirty || saving) {
            return;
        }

        setSaving(true);
        setError(undefined);
        router.patch(
            updateBatchConsumption({
                production_order: orderId,
                batch_consumption: source.id,
            }).url,
            { units },
            {
                preserveScroll: true,
                onError: (serverErrors: Record<string, string>) =>
                    setError(
                        serverErrors.units ?? serverErrors.production_order,
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="space-y-2">
            <FormField
                id={`batch-source-${source.id}-units`}
                label="Unidades"
                help={
                    source.kg_per_unit !== null ? (
                        <>
                            <FormattedNumber
                                value={source.kg_per_unit}
                                kind="quantity"
                            />{' '}
                            kg por unidad
                            {source.kg !== null && (
                                <>
                                    {' · '}
                                    <FormattedNumber
                                        value={source.kg}
                                        kind="quantity"
                                    />{' '}
                                    kg en total
                                </>
                            )}
                        </>
                    ) : (
                        'Sin peso por galón: no se sabe cuántos kilos trae'
                    )
                }
                error={error}
            >
                <IntegerInput
                    value={units}
                    onValueChange={setUnits}
                    onKeyDown={(event) => {
                        preventSubmitOnEnter(event);

                        if (event.key === 'Enter') {
                            save();
                        }
                    }}
                />
            </FormField>
            {isDirty && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={save}
                    disabled={saving}
                >
                    {saving ? 'Guardando...' : 'Guardar unidades'}
                </Button>
            )}
        </div>
    );
}

/**
 * Al enviar o completar: si quedó producto en un envase abierto y cuánto pesó. Si la línea sale solo de este lote, la
 * cuenta de lo gastado en unidades y la sugerencia de bajarlas.
 */
export function BatchUsageFields({
    source,
    usage,
    lineActualKg,
    onChange,
    fieldId,
    errors,
}: {
    source: ProductionOrderBatchConsumption;
    usage: ProductionOrderBatchUsageRow;
    lineActualKg: ProductionOrderProductLineUsageRow['actual_kg'] | null;
    onChange: (
        patch: Partial<Omit<ProductionOrderBatchUsageRow, 'id'>>,
    ) => void;
    fieldId: (field: string) => string;
    errors: Record<string, string | undefined>;
}) {
    const leftover = usage.has_leftover ? usage.leftover_kg : '';
    const summary =
        lineActualKg !== null
            ? batchUsageSummary(lineActualKg, source.kg_per_unit)
            : null;
    const suggestion =
        lineActualKg !== null
            ? batchUnitsSuggestion(
                  lineActualKg,
                  leftover,
                  source.kg_per_unit,
                  source.units,
              )
            : null;

    return (
        <div className="space-y-3">
            {summary && (
                <p className="text-xs text-muted-foreground">
                    Gastado: {summary}
                </p>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <FormField
                    id={fieldId('has_leftover')}
                    label="¿Quedó producto en un cuñete abierto?"
                    error={errors[fieldId('has_leftover')]}
                >
                    {(control) => (
                        <Select
                            value={usage.has_leftover ? 'yes' : 'no'}
                            onValueChange={(value) =>
                                onChange({ has_leftover: value === 'yes' })
                            }
                        >
                            <SelectTrigger {...control}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="no">No</SelectItem>
                                <SelectItem value="yes">Sí</SelectItem>
                            </SelectContent>
                        </Select>
                    )}
                </FormField>
                {usage.has_leftover && (
                    <FormField
                        id={fieldId('leftover_kg')}
                        label="Kilos que quedaron"
                        required
                        help={
                            source.kg_per_unit !== null
                                ? `Pésalo en la báscula: vuelve al saldo de ${source.source_order_number ?? 'su OP'}. No pasa de una unidad (${formatKind(source.kg_per_unit, 'quantity')} kg).`
                                : `Pésalo en la báscula: vuelve al saldo de ${source.source_order_number ?? 'su OP'}.`
                        }
                        error={errors[fieldId('leftover_kg')]}
                    >
                        <DecimalInput
                            placeholder="0,00"
                            value={usage.leftover_kg}
                            onValueChange={(value) =>
                                onChange({ leftover_kg: value })
                            }
                        />
                    </FormField>
                )}
            </div>
            {suggestion && (
                <p className="text-xs text-muted-foreground">{suggestion}</p>
            )}
        </div>
    );
}

/** Lo que se registró al enviar a revisión o al completar, sin campos. */
export function SavedUsage({
    source,
}: {
    source: ProductionOrderBatchConsumption;
}) {
    if (source.leftover_kg !== null) {
        return (
            <p className="text-xs text-muted-foreground">
                Quedaron{' '}
                <FormattedNumber value={source.leftover_kg} kind="quantity" />{' '}
                kg pesados en un cuñete abierto
            </p>
        );
    }

    return source.state === 'applied' ? (
        <p className="text-xs text-muted-foreground">
            No quedó producto en un cuñete abierto
        </p>
    ) : null;
}
