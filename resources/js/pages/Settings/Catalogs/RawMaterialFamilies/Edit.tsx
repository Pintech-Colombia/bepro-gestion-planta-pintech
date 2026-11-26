import { Head, useForm } from '@inertiajs/react';
import { RawMaterialFamilyFields } from '@/components/catalogs/raw-material-family-form';
import { FormActions } from '@/components/form-actions';
import Heading from '@/components/heading';
import { formatForInput } from '@/lib/formatters';
import {
    edit as familiesEdit,
    index as familiesIndex,
    update as familiesUpdate,
} from '@/routes/catalogs/raw-material-families';
import type { RawMaterialFamilyFormData, RawMaterialFamilyRow } from '@/types';

type Props = {
    family: RawMaterialFamilyRow;
    generalTolerance: string;
};

export default function RawMaterialFamiliesEdit({
    family,
    generalTolerance,
}: Props) {
    const form = useForm<RawMaterialFamilyFormData>({
        name: family.name,
        description: family.description ?? '',
        consumption_tolerance_percentage: formatForInput(
            family.consumption_tolerance_percentage,
        ),
        is_active: family.is_active,
    });

    return (
        <>
            <Head title={`Editar ${family.name}`} />

            <div className="space-y-6">
                <Heading
                    title={`Editar «${family.name}»`}
                    description={`Categorías en esta familia: ${family.categories_count}.`}
                />

                <form
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(familiesUpdate(family.id).url);
                    }}
                    className="grid gap-6"
                >
                    <RawMaterialFamilyFields
                        form={form}
                        generalTolerance={generalTolerance}
                    />

                    <FormActions
                        submitLabel="Guardar cambios"

                        processing={form.processing}

                        cancelHref={familiesIndex().url}
                    />
                </form>
            </div>
        </>
    );
}

RawMaterialFamiliesEdit.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Familias de MP', href: familiesIndex() },
        {
            title: props.family.name,
            href: familiesEdit(props.family.id),
        },
    ],
});
