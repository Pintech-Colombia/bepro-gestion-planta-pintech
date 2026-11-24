import type { useForm } from '@inertiajs/react';
import { FormCheckbox, FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { RawMaterialFamilyFormData } from '@/types';

type Props = {
    form: ReturnType<typeof useForm<RawMaterialFamilyFormData>>;
};

export function RawMaterialFamilyFields({ form }: Props) {
    return (
        <div className="grid gap-5">
            <FormField id="name" label="Nombre" error={form.errors.name}>
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
