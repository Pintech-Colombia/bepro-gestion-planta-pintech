import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';

import {
    destroy as removeRemnantConsumption,
    store as planRemnantConsumption,
} from '@/actions/App/Http/Controllers/Production/RemnantConsumptionController';
import { DecimalInput } from '@/components/decimal-input';
import { DetailTable } from '@/components/detail-table';
import type { DetailTableColumn } from '@/components/detail-table';
import { FormError, FormField } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/hooks/use-confirm';
import { formatKind } from '@/lib/formatters';
import { toDecimalPayload } from '@/lib/numeric-input';
import type {
    ProductionOrder,
    ProductionOrderRemnantConsumption,
} from '@/types/production-orders';

const STATE_LABELS: Record<ProductionOrderRemnantConsumption['state'], string> =
    {
        applied: 'Descontado',
        planned: 'Planeado',
        unused: 'No usado',
    };

type ConsumeRemnantsCardProps = {
    orderId: number;
    order: ProductionOrder;
    /** Agregar y quitar saldos (`can.planRemnant`). */
    canPlan: boolean;
    /** El costo solo se muestra con costs.view. */
    showCosts: boolean;
    /** Error al completar: un saldo planeado ya no alcanza. */
    completionError?: string;
};

/** Enter en un campo de la tarjeta no envía el formulario de la orden, que la contiene. */
const preventSubmitOnEnter = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
        event.preventDefault();
    }
};

/**
 * Saldos de PT a usar en la orden: líneas planeadas que se agregan con la orden pendiente, en curso o en revisión, salen
 * en el PDF y se descuentan del saldo al completar la orden. Mientras están planeadas, los galones y el costo son un
 * estimado y la línea se puede quitar.
 */
export function ConsumeRemnantsCard({
    orderId,
    order,
    canPlan,
    showCosts,
    completionError,
}: ConsumeRemnantsCardProps) {
    const confirm = useConfirm();
    const lines = order.remnant_consumptions ?? [];
    const availableRemnants = order.available_remnants ?? [];
    const hasPlannedLines = lines.some((line) => line.state === 'planned');

    const [submitting, setSubmitting] = useState(false);
    const [selectedRemnantId, setSelectedRemnantId] = useState<
        string | number | null
    >(null);
    const [quantityKg, setQuantityKg] = useState('');
    const [notes, setNotes] = useState('');
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});
    const [removeError, setRemoveError] = useState<string | undefined>();

    const comboboxOptions = availableRemnants.map((remnant) => ({
        id: remnant.id,
        // Qué es el saldo antes que de dónde viene: se mezcla en este lote. El buscador mira toda la etiqueta.
        label: `${remnant.product_name} · Lote ${remnant.source_lot_number} (${remnant.source_order_number}) — ${formatKind(remnant.available_quantity_kg, 'quantity')} kg libres`,
    }));

    const handleAdd = () => {
        if (!selectedRemnantId || !quantityKg) {
            setFormErrors({
                ...(!selectedRemnantId
                    ? { remnant_id: 'Selecciona un saldo.' }
                    : {}),
                ...(!quantityKg ? { quantity_kg: 'Escribe los kilos.' } : {}),
            });

            return;
        }

        setSubmitting(true);
        setFormErrors({});
        setRemoveError(undefined);

        router.post(
            planRemnantConsumption({ production_order: orderId }).url,
            {
                remnant_id: selectedRemnantId,
                quantity_kg: toDecimalPayload(quantityKg),
                notes: notes || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedRemnantId(null);
                    setQuantityKg('');
                    setNotes('');
                },
                onError: (errors) => setFormErrors(errors),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const handleRemove = async (line: ProductionOrderRemnantConsumption) => {
        const confirmed = await confirm({
            title: '¿Quitar este saldo de la orden?',
            description: `${line.source_product_name} (${line.source_order_number}). El saldo no se había descontado: sus kilos quedan libres otra vez.`,
            confirmLabel: 'Quitar',
            destructive: true,
        });

        if (!confirmed) {
            return;
        }

        setRemoveError(undefined);
        router.delete(
            removeRemnantConsumption({
                production_order: orderId,
                consumption: line.id,
            }).url,
            {
                preserveScroll: true,
                onError: (errors) =>
                    setRemoveError(
                        errors.remnant_consumption ?? errors.production_order,
                    ),
            },
        );
    };

    if (!canPlan && lines.length === 0 && !order.remnant) {
        return null;
    }

    const columns: DetailTableColumn<ProductionOrderRemnantConsumption>[] = [
        {
            key: 'remnant',
            header: 'Saldo',
            cell: (line) => (
                <>
                    <div className="font-medium">
                        {line.source_product_name}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        Lote {line.source_lot_number} ·{' '}
                        <span className="font-mono">
                            {line.source_order_number}
                        </span>
                    </div>
                </>
            ),
        },
        {
            key: 'kilos',
            header: 'Kilos',
            cell: (line) => (
                <FormattedNumber value={line.quantity_kg} kind="quantity" />
            ),
            align: 'right',
        },
        {
            key: 'gallons',
            header: 'Galones',
            // Planeada, los galones son un estimado: los reales salen al completar. No usada, no hubo galones.
            cell: (line) =>
                line.state === 'unused' ? null : (
                    <>
                        {line.state === 'planned' && '≈ '}
                        <FormattedNumber
                            value={line.quantity_gallons}
                            kind="quantity"
                        />
                    </>
                ),
            align: 'right',
        },
        ...(showCosts
            ? [
                  {
                      key: 'cost',
                      header: 'Costo',
                      cell: (line: ProductionOrderRemnantConsumption) =>
                          line.consumed_cost == null ? null : (
                              <>
                                  {line.state === 'planned' && '≈ '}
                                  <FormattedNumber
                                      value={line.consumed_cost}
                                      kind="cost"
                                  />
                              </>
                          ),
                      align: 'right' as const,
                  },
              ]
            : []),
        {
            key: 'state',
            header: 'Estado',
            cell: (line) => STATE_LABELS[line.state],
        },
        {
            key: 'notes',
            header: 'Observaciones',
            cell: (line) => line.notes ?? null,
        },
        ...(lines.some((line) => line.can.remove)
            ? [
                  {
                      key: 'actions',
                      header: 'Acciones',
                      cell: (line: ProductionOrderRemnantConsumption) =>
                          line.can.remove ? (
                              <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="text-destructive-text"
                                  onClick={() => handleRemove(line)}
                              >
                                  Quitar
                              </Button>
                          ) : null,
                      align: 'right' as const,
                  },
              ]
            : []),
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Saldos de PT a usar</CardTitle>
                {(canPlan || hasPlannedLines) && (
                    <CardDescription>
                        El saldo se descuenta al completar la orden.
                    </CardDescription>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {lines.length > 0 && (
                    <DetailTable
                        rows={lines}
                        rowKey={(line) => line.id}
                        caption="Saldos de PT a usar en esta orden"
                        columns={columns}
                        empty="Esta orden no usa saldos."
                    />
                )}

                <FormError
                    id="remnant_consumption_ids"
                    message={completionError}
                />
                <FormError message={removeError} />

                {canPlan && (
                    <div className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <FormField
                                id="remnant_id"
                                label="Saldo"
                                error={formErrors.remnant_id}
                                className="sm:col-span-2"
                            >
                                <Combobox
                                    options={comboboxOptions}
                                    value={selectedRemnantId}
                                    onChange={setSelectedRemnantId}
                                    placeholder="Buscar por producto, color, lote u OP..."
                                    emptyText="No hay saldos libres en esta bodega."
                                />
                            </FormField>
                            <FormField
                                id="quantity_kg"
                                label="Kilos"
                                error={formErrors.quantity_kg}
                            >
                                <DecimalInput
                                    placeholder="0,00"
                                    value={quantityKg}
                                    onValueChange={setQuantityKg}
                                    onKeyDown={preventSubmitOnEnter}
                                />
                            </FormField>
                        </div>
                        <FormField
                            id="remnant_consumption_notes"
                            label="Observaciones"
                            error={formErrors.notes}
                        >
                            <Input
                                value={notes}
                                maxLength={1000}
                                onChange={(event) =>
                                    setNotes(event.target.value)
                                }
                                onKeyDown={preventSubmitOnEnter}
                            />
                        </FormField>
                        <FormError message={formErrors.production_order} />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleAdd}
                            disabled={
                                submitting || availableRemnants.length === 0
                            }
                        >
                            {submitting ? 'Agregando...' : 'Agregar saldo'}
                        </Button>
                    </div>
                )}

                {order.remnant && (
                    <div className="space-y-1 border-t border-border pt-4">
                        <h3 className="text-base font-semibold">
                            Saldo que dejó esta orden
                        </h3>
                        <p className="text-sm">
                            <FormattedNumber
                                value={order.remnant.original_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg (≈{' '}
                            <FormattedNumber
                                value={order.remnant.original_quantity_gallons}
                                kind="quantity"
                            />{' '}
                            gal)
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {order.remnant.status_label} · Disponible:{' '}
                            <FormattedNumber
                                value={order.remnant.available_quantity_kg}
                                kind="quantity"
                            />{' '}
                            kg
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
