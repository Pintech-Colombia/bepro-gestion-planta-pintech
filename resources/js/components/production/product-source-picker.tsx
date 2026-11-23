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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { proposedBatchUnits, proposedRemnantKg } from '@/lib/batch-usage';
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
    /** Lo que le falta a la línea (null en una agregada): al elegir, se propone esa cantidad. */
    missingKg?: string | null;
    /** «Producto adicional»: solo ofrece productos que la orden no lleva (cambia el aviso cuando no hay). */
    otherProducts?: boolean;
    label: string;
};

type SourceKind = 'remnant' | 'batch';

const REMNANT_PREFIX = 'saldo-';
const BATCH_PREFIX = 'lote-';

/**
 * El buscador de fuentes de «Productos a usar»: primero se elige si es un saldo (en kilos) o un lote de PT (en unidades
 * enteras), cada uno en su pestaña, y después cuál, de la bodega de la orden, con lo libre: los saldos del más viejo al
 * más nuevo (se gasta primero lo que ya está abierto) y los lotes por fecha. Busca por producto, color, lote u OP. Al
 * elegir, propone lo que le falta a la línea; la persona lo confirma o lo cambia. Agrega la fuente al momento: no espera
 * al formulario.
 */
export function ProductSourcePicker({
    orderId,
    lineId,
    idPrefix,
    remnants,
    batches,
    missingKg = null,
    otherProducts = false,
    label,
}: ProductSourcePickerProps) {
    const [kind, setKind] = useState<SourceKind>(
        remnants.length > 0 || batches.length === 0 ? 'remnant' : 'batch',
    );
    const [selected, setSelected] = useState<string | null>(null);
    const [quantity, setQuantity] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const options: ComboboxOptionType[] =
        kind === 'remnant'
            ? remnants.map((remnant) => ({
                  id: `${REMNANT_PREFIX}${remnant.id}`,
                  // En la línea, el producto ya está en su título: solo en «Producto adicional» se nombra.
                  label: `Lote ${remnant.source_lot_number}${otherProducts ? ` · ${remnant.product_name}` : ''} (${remnant.source_order_number})`,
                  description: `${formatKind(remnant.available_quantity_kg, 'quantity')} kg libres`,
              }))
            : batches.map((batch) => ({
                  id: `${BATCH_PREFIX}${batch.id}`,
                  label: `Lote ${batch.lot_number ?? ''}${otherProducts ? ` · ${batch.product_name}` : ''}${batch.presentation_label ? ` · ${batch.presentation_label}` : ''} (${batch.source_order_number ?? ''})`,
                  description: [
                      `${formatKind(batch.free_units, 'units')} libres`,
                      batch.kg_per_unit !== null
                          ? `${formatKind(batch.kg_per_unit, 'quantity')} kg c/u`
                          : 'sin peso por galón',
                  ].join(' · '),
              }));

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

    const choose = (value: string) => {
        setSelected(value);
        setErrors({});

        const chosenRemnant = remnants.find(
            (item) => `${REMNANT_PREFIX}${item.id}` === value,
        );
        const chosenBatch = batches.find(
            (item) => `${BATCH_PREFIX}${item.id}` === value,
        );

        setQuantity(
            chosenRemnant
                ? proposedRemnantKg(
                      missingKg,
                      chosenRemnant.available_quantity_kg,
                  )
                : chosenBatch
                  ? proposedBatchUnits(
                        missingKg,
                        chosenBatch.kg_per_unit,
                        chosenBatch.free_units,
                    )
                  : '',
        );
    };

    return (
        <div className="space-y-3">
            <FormField
                id={`${idPrefix}-source`}
                label={label}
                error={sourceError}
            >
                {(control) => (
                    // min-w-0: en la celda de la grilla, el nombre largo del lote no ensancha la tarjeta (se trunca).
                    <div className="min-w-0 space-y-2">
                        <Tabs
                            value={kind}
                            onValueChange={(value) => {
                                setKind(value as SourceKind);
                                reset();
                                setErrors({});
                            }}
                        >
                            <TabsList className="w-full">
                                <TabsTrigger value="remnant">
                                    Saldos ({remnants.length})
                                </TabsTrigger>
                                <TabsTrigger value="batch">
                                    Lotes ({batches.length})
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <Combobox
                            {...control}
                            options={options}
                            value={selected}
                            onChange={(value) => choose(String(value))}
                            placeholder={
                                kind === 'remnant'
                                    ? 'Buscar saldo por producto, color, lote u OP...'
                                    : 'Buscar lote por producto, color, lote u OP...'
                            }
                            emptyText={
                                kind === 'remnant'
                                    ? 'No hay saldos libres en esta bodega.'
                                    : 'No hay lotes con unidades libres en esta bodega.'
                            }
                            disabled={options.length === 0}
                        />
                        {options.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                {otherProducts
                                    ? `No hay ${kind === 'remnant' ? 'saldos libres' : 'lotes con unidades libres'} de productos que la orden no lleve en esta bodega.`
                                    : `No hay ${kind === 'remnant' ? 'saldos libres' : 'lotes con unidades libres'} de este producto en esta bodega.`}
                            </p>
                        )}
                    </div>
                )}
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
