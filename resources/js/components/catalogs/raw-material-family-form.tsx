import type { useForm } from '@inertiajs/react';
import { DecimalInput } from '@/components/decimal-input';
import { FormCheckbox, FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatKind } from '@/lib/formatters';
import { NUMBER_TYPES } from '@/lib/number-types';
import type { RawMaterialFamilyFormData } from '@/types';

type Props = {
    form: ReturnType<typeof useForm<RawMaterialFamilyFormData>>;
    /** El % general (`config/production.php`), el que usa la familia sin el suyo. */
    generalTolerance: string;
};

export function RawMaterialFamilyFields({ form, generalTolerance }: Props) {
    return (
        <div className="grid gap-5">
            <FormField
                id="name"
                label="Nombre"
                required
                error={form.errors.name}
            >
                <Input
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    maxLength={100}
                    placeholder="ADITIVO"
                />
            </FormField>

            <FormField
                id="description"
                label="Descripción"
                error={form.errors.description}
            >
                <Textarea
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    maxLength={1000}
                    rows={2}
                />
            </FormField>

            <FormField
                id="consumption_tolerance_percentage"
                label="Tolerancia de consumo (%)"
                help={`Cuánto puede pasarse el consumo real de lo planeado en una OP antes de pedir el motivo. Vacío: el general (${formatKind(generalTolerance, 'percent')}).`}
                error={form.errors.consumption_tolerance_percentage}
            >
                <DecimalInput
                    {...NUMBER_TYPES.percentage}
                    value={form.data.consumption_tolerance_percentage}
                    onValueChange={(value) =>
                        form.setData('consumption_tolerance_percentage', value)
                    }
                    placeholder={formatKind(generalTolerance, 'percent')}
                />
            </FormField>

            <FormCheckbox
                id="is_active"
                label="Familia activa"
                help={
                    form.data.is_active
                        ? undefined
                        : 'Inactiva, no se podrá elegir al registrar categorías nuevas. Las que ya la tienen la conservan.'
                }
                error={form.errors.is_active}
                checked={form.data.is_active}
                onCheckedChange={(checked) =>
                    form.setData('is_active', checked === true)
                }
            />
        </div>
    );
}
