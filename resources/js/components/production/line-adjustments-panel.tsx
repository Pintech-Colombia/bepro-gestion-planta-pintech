import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';

import {
    destroy as destroyLineAdjustment,
    store as storeLineAdjustment,
} from '@/actions/App/Http/Controllers/Production/LineAdjustmentController';
import { DecimalInput } from '@/components/decimal-input';
import { FormattedNumber } from '@/components/formatted-number';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/hooks/use-confirm';
import { toDecimalPayload } from '@/lib/numeric-input';
import type {
    ProductionOrderLineAdjustment,
    RawMaterialOption,
} from '@/types/production-orders';

type LineAdjustmentsPanelProps = {
    orderId: number;
    adjustments: ProductionOrderLineAdjustment[];
    rawMaterials: RawMaterialOption[];
    isCompleted: boolean;
    isReadOnly: boolean;
    /** El formulario para agregar un ajuste: lo abre el botón del encabezado de la sección de consumo. */
    showForm?: boolean;
    /** Al guardar un ajuste, el formulario se cierra. */
    onFormDone?: () => void;
};

export function LineAdjustmentsPanel({
    orderId,
    adjustments,
    rawMaterials,
    isCompleted,
    isReadOnly,
    showForm = false,
    onFormDone,
}: LineAdjustmentsPanelProps) {
    const confirm = useConfirm();
    const showFormBlock = !isReadOnly && showForm;

    // Sin ajustes ni formulario abierto, la sección no muestra nada (el botón está en su encabezado).
    if (adjustments.length === 0 && !showFormBlock && !isCompleted) {
        return null;
    }

    return (
        <div className="space-y-3">
            <p className="text-sm font-medium">Ajustes de línea</p>

            {adjustments.length > 0 && (
                <div className="overflow-hidden rounded-md border">
                    <div className="relative overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="p-3 text-left">
                                        Materia prima
                                    </th>
                                    <th className="p-3 text-right">Cantidad</th>
                                    <th className="p-3 text-left">Motivo</th>
                                    {!isReadOnly && (
                                        <th className="w-12 p-3"></th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {adjustments.map((adjustment) => (
                                    <tr
                                        key={adjustment.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 font-medium">
                                            {adjustment.raw_material?.code ??
                                                'N/A'}
                                        </td>
                                        <td className="p-3 text-right">
                                            <FormattedNumber
                                                value={adjustment.quantity}
                                                maxDecimals={4}
                                            />
                                        </td>
                                        <td className="p-3 text-muted-foreground">
                                            {adjustment.reason}
                                        </td>
                                        {!isReadOnly && (
                                            <td className="p-3">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-7 w-7 text-destructive hover:text-destructive"
                                                    onClick={async () => {
                                                        if (
                                                            await confirm({
                                                                title: '¿Eliminar este ajuste de línea?',
                                                                confirmLabel:
                                                                    'Eliminar',
                                                                destructive: true,
                                                            })
                                                        ) {
                                                            router.delete(
                                                                destroyLineAdjustment(
                                                                    {
                                                                        production_order:
                                                                            orderId,
                                                                        adjustment:
                                                                            adjustment.id,
                                                                    },
                                                                ).url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {showFormBlock && (
                <LineAdjustmentForm
                    orderId={orderId}
                    rawMaterials={rawMaterials}
                    onAdded={onFormDone}
                />
            )}

            {isCompleted && adjustments.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    No se registraron ajustes de línea en esta orden.
                </p>
            )}
        </div>
    );
}

function LineAdjustmentForm({
    orderId,
    rawMaterials,
    onAdded,
}: {
    orderId: number;
    rawMaterials: RawMaterialOption[];
    onAdded?: () => void;
}) {
    const [rawMaterialId, setRawMaterialId] = useState<number | null>(null);
    const [quantity, setQuantity] = useState('');
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});

    const comboboxOptions = rawMaterials.map((rawMaterial) => ({
        id: rawMaterial.id,
        label: rawMaterial.label,
    }));

    const handleAdd = () => {
        if (!rawMaterialId || !quantity || !reason.trim()) {
            const errors: Record<string, string> = {};

            if (!rawMaterialId) {
                errors.raw_material_id = 'Seleccione una MP.';
            }

            if (!quantity) {
                errors.quantity = 'Ingrese cantidad.';
            }

            if (!reason.trim()) {
                errors.reason = 'Ingrese motivo.';
            }

            setFormErrors(errors);

            return;
        }

        setSubmitting(true);
        setFormErrors({});

        router.post(
            storeLineAdjustment({ production_order: orderId }).url,
            {
                raw_material_id: rawMaterialId,
                quantity: toDecimalPayload(quantity),
                reason: reason.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRawMaterialId(null);
                    setQuantity('');
                    setReason('');
                    onAdded?.();
                },
                onError: (errors) => {
                    setFormErrors(errors as Record<string, string>);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="space-y-3 rounded-md border border-dashed border-border bg-muted/30 p-3">
            <p className="text-xs text-muted-foreground">
                Materia prima adicional, fuera de la fórmula.
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div className="space-y-1">
                    <Combobox
                        options={comboboxOptions}
                        value={rawMaterialId}
                        onChange={(value) => setRawMaterialId(Number(value))}
                        placeholder="Materia prima..."
                        emptyText="Sin resultados"
                    />
                    {formErrors.raw_material_id && (
                        <p className="text-xs text-destructive">
                            {formErrors.raw_material_id}
                        </p>
                    )}
                </div>
                <div className="space-y-1">
                    <DecimalInput
                        placeholder="Cantidad"
                        className="h-9"
                        value={quantity}
                        onValueChange={setQuantity}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                            }
                        }}
                    />
                    {formErrors.quantity && (
                        <p className="text-xs text-destructive">
                            {formErrors.quantity}
                        </p>
                    )}
                </div>
                <div className="space-y-1">
                    <Input
                        placeholder="Motivo (ej: corrección viscosidad)"
                        className="h-9"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        maxLength={500}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                            }
                        }}
                    />
                    {formErrors.reason && (
                        <p className="text-xs text-destructive">
                            {formErrors.reason}
                        </p>
                    )}
                </div>
            </div>
            {formErrors.production_order && (
                <p className="text-xs text-destructive">
                    {formErrors.production_order}
                </p>
            )}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleAdd}
                disabled={submitting}
            >
                {submitting ? 'Guardando...' : 'Guardar ajuste'}
            </Button>
        </div>
    );
}
