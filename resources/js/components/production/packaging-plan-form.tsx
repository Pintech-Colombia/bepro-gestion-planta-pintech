import { router } from '@inertiajs/react';
import { useState } from 'react';

import { store as storePackagingPlan } from '@/actions/App/Http/Controllers/Production/PackagingPlanController';
import { IntegerInput } from '@/components/integer-input';
import { PresentationSelect } from '@/components/presentation-select';
import { Button } from '@/components/ui/button';
import { variantPresentationLabel } from '@/lib/variant-label';
import type { VariantOption } from '@/types/production-orders';

/** Agregar una presentación al plan de envasado: una sola vez por orden. */
export function PackagingPlanForm({
    orderId,
    availableVariants,
}: {
    orderId: number;
    availableVariants: VariantOption[];
}) {
    const [variantId, setVariantId] = useState<number | null>(null);
    const [plannedUnits, setPlannedUnits] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});

    const variantOptions = availableVariants.map((variant) => ({
        id: variant.id,
        label: variantPresentationLabel(variant),
    }));

    const handleAdd = () => {
        if (!variantId || !plannedUnits) {
            const errors: Record<string, string> = {};

            if (!variantId) {
                errors.product_variant_id = 'Seleccione una presentación.';
            }

            if (!plannedUnits) {
                errors.planned_units = 'Ingrese unidades.';
            }

            setFormErrors(errors);

            return;
        }

        setSubmitting(true);
        setFormErrors({});

        router.post(
            storePackagingPlan({ production_order: orderId }).url,
            {
                product_variant_id: variantId,
                planned_units: plannedUnits,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setVariantId(null);
                    setPlannedUnits('');
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
                Agregar presentación al plan de envasado
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div className="space-y-1 sm:col-span-2">
                    <PresentationSelect
                        aria-label="Presentación"
                        options={variantOptions}
                        value={variantId}
                        onChange={(value) => setVariantId(Number(value))}
                    />
                    {formErrors.product_variant_id && (
                        <p className="text-xs text-destructive">
                            {formErrors.product_variant_id}
                        </p>
                    )}
                </div>
                <div className="space-y-1">
                    <IntegerInput
                        placeholder="Unidades planeadas"
                        className="h-9"
                        value={plannedUnits}
                        onValueChange={setPlannedUnits}
                    />
                    {formErrors.planned_units && (
                        <p className="text-xs text-destructive">
                            {formErrors.planned_units}
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
                {submitting ? 'Guardando...' : 'Agregar presentación'}
            </Button>
        </div>
    );
}
