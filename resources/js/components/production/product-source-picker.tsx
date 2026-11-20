import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';

import { store as planBatchConsumption } from '@/actions/App/Http/Controllers/Production/BatchConsumptionController';
import { store as planRemnantConsumption } from '@/actions/App/Http/Controllers/Production/RemnantConsumptionController';
import { DecimalInput } from '@/components/decimal-input';
import { FormError, FormField } from '@/components/form-field';
import { IntegerInput } from '@/components/integer-input';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOptionType } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { formatKind } from '@/lib/formatters';
import { toDecimalPayload } from '@/lib/numeric-input';
import type {
    ProductionOrderAvailableBatch,
    ProductionOrderAvailableRemnant,
} from '@/types/production-orders';

/** Enter en un campo del buscador no envía el formulario de la orden, que lo contiene. */
export const preventSubmitOnEnter = (
    event: KeyboardEvent<HTMLInputElement>,
) => {
    if (event.key === 'Enter') {
        event.preventDefault();
    }
};

type ProductSourcePickerProps = {
    orderId: number;
    /** La línea a la que va la fuente; null: la del producto o, si la orden no lo lleva, una línea agregada nueva. */
    lineId: number | null;
    /** Prefijo de los `id` de los campos: hay un buscador por línea. */
    idPrefix: string;
    /** Los saldos y lotes que se ofrecen (ya filtrados por producto y sin los lotes que la línea ya tiene). */
    remnants: ProductionOrderAvailableRemnant[];
    batches: ProductionOrderAvailableBatch[];
    label: string;
    emptyText: string;
};

const REMNANT_PREFIX = 'saldo-';
const BATCH_PREFIX = 'lote-';

/**
 * El buscador de fuentes de «Productos a usar»: un saldo (en kilos) o un lote de PT (en unidades enteras) de la bodega de
 * la orden, con lo libre. Busca por producto, color, lote u OP. Agrega la fuente al momento: no espera al formulario.
 */
export function ProductSourcePicker({
    orderId,
    lineId,
    idPrefix,
    remnants,
    batches,
    label,
    emptyText,
}: ProductSourcePickerProps) {
    const [selected, setSelected] = useState<string | null>(null);
    const [quantity, setQuantity] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const options: ComboboxOptionType[] = [
        ...remnants.map((remnant) => ({
            id: `${REMNANT_PREFIX}${remnant.id}`,
            label: `Saldo · ${remnant.product_name} · Lote ${remnant.source_lot_number} (${remnant.source_order_number})`,
            description: `${formatKind(remnant.available_quantity_kg, 'quantity')} kg libres`,
        })),
        ...batches.map((batch) => ({
            id: `${BATCH_PREFIX}${batch.id}`,
            label: `Lote ${batch.lot_number ?? ''} · ${batch.product_name}${batch.presentation_label ? ` · ${batch.presentation_label}` : ''} (${batch.source_order_number ?? ''})`,
            description: [
                `${formatKind(batch.free_units, 'units')} libres`,
                batch.kg_per_unit !== null
                    ? `${formatKind(batch.kg_per_unit, 'quantity')} kg c/u`
                    : 'sin peso por galón',
            ].join(' · '),
        })),
    ];

    const remnant = selected?.startsWith(REMNANT_PREFIX)
        ? remnants.find((item) => `${REMNANT_PREFIX}${item.id}` === selected)
        : undefined;
    const batch = selected?.startsWith(BATCH_PREFIX)
        ? batches.find((item) => `${BATCH_PREFIX}${item.id}` === selected)
        : undefined;

    const reset = () => {
        setSelected(null);
        setQuantity('');
        setNotes('');
    };

    const handleAdd = () => {
        if (!remnant && !batch) {
            setErrors({ source: 'Elige un saldo o un lote.' });

            return;
        }

        if (quantity === '') {
            setErrors({
                quantity: remnant
                    ? 'Escribe los kilos.'
                    : 'Escribe las unidades.',
            });

            return;
        }

        setSubmitting(true);
        setErrors({});

        const lineParam =
            lineId === null ? {} : { production_order_product_line_id: lineId };
        const options = {
            preserveScroll: true,
            onSuccess: reset,
            onError: (serverErrors: Record<string, string>) =>
                setErrors(serverErrors),
            onFinish: () => setSubmitting(false),
        };

        if (remnant) {
            router.post(
                planRemnantConsumption({ production_order: orderId }).url,
                {
                    remnant_id: remnant.id,
                    quantity_kg: toDecimalPayload(quantity),
                    notes: notes || undefined,
                    ...lineParam,
                },
                options,
            );
        } else if (batch) {
            router.post(
                planBatchConsumption({ production_order: orderId }).url,
                {
                    finished_product_batch_id: batch.id,
                    units: quantity,
                    ...lineParam,
                },
                options,
            );
        }
    };

    const sourceError =
        errors.source ?? errors.remnant_id ?? errors.finished_product_batch_id;
    const quantityError = errors.quantity ?? errors.quantity_kg ?? errors.units;
    const otherError =
        errors.production_order_product_line_id ?? errors.production_order;

    return (
        <div className="space-y-3">
            <FormField
                id={`${idPrefix}-source`}
                label={label}
                error={sourceError}
            >
                <Combobox
                    options={options}
                    value={selected}
                    onChange={(value) => {
                        setSelected(String(value));
                        setQuantity('');
                        setErrors({});
                    }}
                    placeholder="Buscar por producto, color, lote u OP..."
                    emptyText={emptyText}
                    disabled={options.length === 0}
                />
            </FormField>

            {remnant && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField
                        id={`${idPrefix}-quantity`}
                        label="Kilos a usar"
                        help={`Libres: ${formatKind(remnant.available_quantity_kg, 'quantity')} kg`}
                        error={quantityError}
                    >
                        <DecimalInput
                            placeholder="0,00"
                            value={quantity}
                            onValueChange={setQuantity}
                            onKeyDown={preventSubmitOnEnter}
                        />
                    </FormField>
                    <FormField
                        id={`${idPrefix}-notes`}
                        label="Observaciones"
                        error={errors.notes}
                    >
                        <Input
                            value={notes}
                            maxLength={1000}
                            onChange={(event) => setNotes(event.target.value)}
                            onKeyDown={preventSubmitOnEnter}
                        />
                    </FormField>
                </div>
            )}

            {batch && (
                <FormField
                    id={`${idPrefix}-quantity`}
                    label="Unidades a sacar de bodega"
                    help={`Libres: ${formatKind(batch.free_units, 'units')}`}
                    error={quantityError}
                >
                    <IntegerInput
                        placeholder="0"
                        value={quantity}
                        onValueChange={setQuantity}
                        onKeyDown={preventSubmitOnEnter}
                    />
                </FormField>
            )}

            <FormError message={otherError} />

            {(remnant || batch) && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={handleAdd}
                    disabled={submitting}
                >
                    {submitting ? 'Agregando...' : 'Agregar'}
                </Button>
            )}
        </div>
    );
}
