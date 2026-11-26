import { Head, useForm } from '@inertiajs/react';
import { RawMaterialCategoryFields } from '@/components/catalogs/raw-material-category-form';
import { FormActions } from '@/components/form-actions';
import Heading from '@/components/heading';
import {
    create as categoriesCreate,
    index as categoriesIndex,
    store as categoriesStore,
} from '@/routes/catalogs/raw-material-categories';
import type { RawMaterialCategoryFormData } from '@/types';

type Props = {
    typeOptions: { value: string; label: string }[];
    familyOptions: { value: string; label: string }[];
};

export default function RawMaterialCategoriesCreate({
    typeOptions,
    familyOptions,
}: Props) {
    const form = useForm<RawMaterialCategoryFormData>({
        name: '',
        description: '',
        family_id: '',
        type: 'chemical',
        is_active: true,
    });

    return (
        <>
            <Head title="Nueva categoría de materia prima" />

            <div className="space-y-6">
                <Heading
                    title="Nueva categoría de materia prima"
                    description="Elige bien el tipo de insumo: no se podrá cambiar cuando la categoría tenga materias primas."
                />

                <form
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(categoriesStore().url);
                    }}
                    className="grid gap-6"
                >
                    <RawMaterialCategoryFields
                        form={form}
                        typeOptions={typeOptions}
                        familyOptions={familyOptions}
                    />

                    <FormActions
                        submitLabel="Guardar"

                        processing={form.processing}

                        cancelHref={categoriesIndex().url}
                    />
                </form>
            </div>
        </>
    );
}

RawMaterialCategoriesCreate.layout = {
    breadcrumbs: [
        { title: 'Categorías de MP', href: categoriesIndex() },
        { title: 'Nueva categoría de materia prima', href: categoriesCreate() },
    ],
};
