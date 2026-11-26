import { Head, useForm } from '@inertiajs/react';
import { RawMaterialFamilyFields } from '@/components/catalogs/raw-material-family-form';
import { FormActions } from '@/components/form-actions';
import Heading from '@/components/heading';
import {
    create as familiesCreate,
    index as familiesIndex,
    store as familiesStore,
} from '@/routes/catalogs/raw-material-families';
import type { RawMaterialFamilyFormData } from '@/types';

type Props = {
    generalTolerance: string;
};

export default function RawMaterialFamiliesCreate({ generalTolerance }: Props) {
    const form = useForm<RawMaterialFamilyFormData>({
        name: '',
        description: '',
        consumption_tolerance_percentage: '',
        is_active: true,
    });

    return (
        <>
            <Head title="Nueva familia de materia prima" />

            <div className="space-y-6">
                <Heading
                    title="Nueva familia de materia prima"
                    description="Quedará disponible al registrar categorías de materia prima."
                />

                <form
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(familiesStore().url);
                    }}
                    className="grid gap-6"
                >
                    <RawMaterialFamilyFields
                        form={form}
                        generalTolerance={generalTolerance}
                    />

                    <FormActions
                        submitLabel="Guardar"

                        processing={form.processing}

                        cancelHref={familiesIndex().url}
                    />
                </form>
            </div>
        </>
    );
}

RawMaterialFamiliesCreate.layout = {
    breadcrumbs: [
        { title: 'Familias de MP', href: familiesIndex() },
        { title: 'Nueva familia de materia prima', href: familiesCreate() },
    ],
};
