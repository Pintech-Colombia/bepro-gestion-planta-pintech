import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';

import { DecimalInput } from '@/components/decimal-input';
import { FormActions } from '@/components/form-actions';
import { FormError, FormField } from '@/components/form-field';
import { FormLineItem } from '@/components/form-line-item';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { clearListErrors } from '@/lib/form-errors';
import { withDecimalPayloads } from '@/lib/numeric-input';

type RawMaterial = { id: number; code: string };
type ProductOption = { id: number; name: string };
/** `is_mass`: unidad de peso (kg, g); un producto como ingrediente solo va en una de estas. */
export type UnitOption = {
    id: number;
    name: string;
    symbol: string;
    is_mass: boolean;
};
/** Un producto que puede ir como ingrediente, con sus presentaciones activas para «Se costea como». */
export type IngredientProduct = {
    id: number;
    name: string;
    variants: Array<{ id: number; label: string }>;
};

/** Qué es la línea: una materia prima o un producto (PT consumible). */
export type IngredientKind = 'raw_material' | 'product';

export type DetailRow = {
    kind: IngredientKind;
    raw_material_id: string;
    product_id: string;
    /** «Se costea como» (solo producto): '' sin elegir, 'bulk' a granel o el id de una presentación. */
    costing: string;
    quantity: string;
    unit_of_measure_id: string;
};

/** El valor de «Se costea como» que se guarda: granel o una presentación. */
export const BULK_COSTING = 'bulk';

export type FormulaFormData = {
    product_id: string;
    name: string;
    notes: string;
    details: DetailRow[];
};

/**
 * Lo que se envía al crear o editar: cada línea con su materia prima **o** su producto, «Se costea como» en las columnas
 * que guarda el servidor (`costing_basis`, `costing_variant_id`) y la cantidad con punto decimal.
 */
export function formulaPayload<T extends FormulaFormData>(data: T) {
    return {
        ...data,
        details: data.details.map(({ kind, costing, ...detail }) => {
            const isProduct = kind === 'product';

            return withDecimalPayloads(
                {
                    raw_material_id: isProduct ? '' : detail.raw_material_id,
                    product_id: isProduct ? detail.product_id : '',
                    costing_basis:
                        !isProduct || costing === ''
                            ? ''
                            : costing === BULK_COSTING
                              ? BULK_COSTING
                              : 'presentation',
                    costing_variant_id:
                        isProduct && costing !== '' && costing !== BULK_COSTING
                            ? costing
                            : '',
                    quantity: detail.quantity,
                    unit_of_measure_id: detail.unit_of_measure_id,
                },
                ['quantity'],
            );
        }),
    };
}

/** El «Se costea como» del formulario a partir de lo guardado (al editar). */
export function costingFromSaved(
    costingBasis: string | null,
    costingVariantId: number | null,
): string {
    if (costingBasis === BULK_COSTING) {
        return BULK_COSTING;
    }

    return costingVariantId !== null ? String(costingVariantId) : '';
}

type FormulaFormProps = {
    data: FormulaFormData;
    errors: Partial<Record<string, string>>;
    processing: boolean;
    products: ProductOption[];
    rawMaterials: RawMaterial[];
    ingredientProducts: IngredientProduct[];
    units: UnitOption[];
    submitLabel: string;
    /** «Cancelar»: vuelve a la pantalla de donde se llegó. */
    cancelHref: string;
    heading: string;
    description: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    setData: <K extends keyof FormulaFormData>(
        key: K,
        value: FormulaFormData[K],
    ) => void;
    clearErrors: (...fields: never[]) => void;
    lockProduct?: boolean;
};

const emptyDetail = (): DetailRow => ({
    kind: 'raw_material',
    raw_material_id: '',
    product_id: '',
    costing: '',
    quantity: '',
    unit_of_measure_id: '',
});

export function createEmptyFormulaDetail(): DetailRow {
    return emptyDetail();
}

export function FormulaForm({
    data,
    errors,
    processing,
    products,
    rawMaterials,
    ingredientProducts,
    units,
    submitLabel,
    cancelHref,
    heading,
    description,
    onSubmit,
    setData,
    clearErrors,
    lockProduct = false,
}: FormulaFormProps) {
    const productOptions = products.map((product) => ({
        id: String(product.id),
        label: product.name,
    }));

    const rawMaterialOptions = rawMaterials.map((rawMaterial) => ({
        id: String(rawMaterial.id),
        label: rawMaterial.code,
    }));

    // Un producto no puede ir en su propia fórmula (el servidor lo vuelve a revisar, con los ciclos).
    const ingredientProductOptions = ingredientProducts
        .filter((product) => String(product.id) !== data.product_id)
        .map((product) => ({ id: String(product.id), label: product.name }));
    const massUnits = units.filter((unit) => unit.is_mass);

    const costingOptions = (productId: string) => [
        { value: BULK_COSTING, label: 'Granel (sin envase)' },
        ...(
            ingredientProducts.find(
                (product) => String(product.id) === productId,
            )?.variants ?? []
        ).map((variant) => ({
            value: String(variant.id),
            label: variant.label,
        })),
    ];

    const addDetail = () => {
        setData('details', [...data.details, emptyDetail()]);
    };

    const removeDetail = (index: number) => {
        clearListErrors(errors, clearErrors, 'details');
        setData(
            'details',
            data.details.filter((_, currentIndex) => currentIndex !== index),
        );
    };

    const updateDetail = (index: number, changes: Partial<DetailRow>) => {
        setData(
            'details',
            data.details.map((detail, currentIndex) =>
                currentIndex === index ? { ...detail, ...changes } : detail,
            ),
        );
    };

    // Cambiar el tipo vacía el ingrediente y «Se costea como»; la unidad, si ya no sirve (un producto va en peso).
    const changeKind = (index: number, kind: IngredientKind) => {
        const unit = units.find(
            (option) =>
                String(option.id) === data.details[index].unit_of_measure_id,
        );

        updateDetail(index, {
            kind,
            raw_material_id: '',
            product_id: '',
            costing: '',
            unit_of_measure_id:
                kind === 'product' && unit && !unit.is_mass
                    ? ''
                    : data.details[index].unit_of_measure_id,
        });
    };

    return (
        <form noValidate onSubmit={onSubmit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>{heading}</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2">
                    <FormField
                        id="product_id"
                        label="Producto"
                        required
                        error={errors.product_id}
                        help={
                            lockProduct
                                ? 'El producto no se puede cambiar al editar una fórmula existente.'
                                : undefined
                        }
                    >
                        <Combobox
                            options={productOptions}
                            value={data.product_id}
                            onChange={(value) =>
                                setData('product_id', String(value))
                            }
                            placeholder="Busca o selecciona el producto..."
                            disabled={lockProduct}
                        />
                    </FormField>

                    <FormField
                        id="name"
                        label="Nombre"
                        error={errors.name}
                        help="Opcional. Distingue las fórmulas activas de un mismo producto al crear una orden de producción."
                    >
                        <Input
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            maxLength={60}
                            placeholder="Ej: RAL 7035"
                        />
                    </FormField>

                    <FormField
                        id="notes"
                        label="Notas / Observaciones"
                        help="Se imprimen en la orden de producción como indicaciones para la planta."
                        error={errors.notes}
                        className="md:col-span-2"
                    >
                        <Textarea
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                            placeholder="Ej: Fórmula para tono azul celeste estándar, sin modificaciones..."
                            className="min-h-[80px]"
                        />
                    </FormField>
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1.5">
                        <CardTitle>Ingredientes por Galón</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addDetail}
                        className="self-start sm:self-auto"
                    >
                        <Plus className="mr-1 h-4 w-4" />
                        Agregar ingrediente
                    </Button>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="space-y-3">
                        {data.details.map((detail, index) => (
                            <FormLineItem
                                key={index}
                                index={index}
                                label="Paso"
                                labelsOnFirstRowOnly
                                onRemove={
                                    data.details.length > 1
                                        ? () => removeDetail(index)
                                        : undefined
                                }
                                className="md:grid-cols-12"
                            >
                                {/* En el celular el número va en el encabezado del bloque. */}
                                <div className="hidden content-start gap-2 md:col-span-1 md:grid">
                                    <Label>Paso</Label>
                                    <p className="flex h-9 items-center justify-center text-sm font-medium text-muted-foreground tabular-nums">
                                        {index + 1}
                                    </p>
                                </div>

                                <FormField
                                    id={`details.${index}.kind`}
                                    label="Tipo"
                                    className="md:col-span-2"
                                >
                                    {(control) => (
                                        <Select
                                            value={detail.kind}
                                            onValueChange={(value) =>
                                                changeKind(
                                                    index,
                                                    value as IngredientKind,
                                                )
                                            }
                                        >
                                            <SelectTrigger {...control}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="raw_material">
                                                    Materia prima
                                                </SelectItem>
                                                <SelectItem value="product">
                                                    Producto
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    )}
                                </FormField>

                                {detail.kind === 'product' ? (
                                    <FormField
                                        id={`details.${index}.product_id`}
                                        label="Producto"
                                        required
                                        error={
                                            errors[
                                                `details.${index}.product_id`
                                            ] ??
                                            errors[
                                                `details.${index}.raw_material_id`
                                            ]
                                        }
                                        className="md:col-span-4"
                                    >
                                        <Combobox
                                            options={ingredientProductOptions}
                                            value={detail.product_id}
                                            onChange={(value) =>
                                                updateDetail(index, {
                                                    product_id: String(value),
                                                    costing: '',
                                                })
                                            }
                                            placeholder="Producto..."
                                        />
                                    </FormField>
                                ) : (
                                    <FormField
                                        id={`details.${index}.raw_material_id`}
                                        label="Materia prima"
                                        required
                                        error={
                                            errors[
                                                `details.${index}.raw_material_id`
                                            ]
                                        }
                                        className="md:col-span-4"
                                    >
                                        <Combobox
                                            options={rawMaterialOptions}
                                            value={detail.raw_material_id}
                                            onChange={(value) =>
                                                updateDetail(index, {
                                                    raw_material_id:
                                                        String(value),
                                                })
                                            }
                                            placeholder="Materia..."
                                        />
                                    </FormField>
                                )}

                                <FormField
                                    id={`details.${index}.quantity`}
                                    label="Cantidad (por galón)"
                                    required
                                    error={errors[`details.${index}.quantity`]}
                                    className="md:col-span-2"
                                >
                                    <DecimalInput
                                        value={detail.quantity}
                                        onValueChange={(value) =>
                                            updateDetail(index, {
                                                quantity: value,
                                            })
                                        }
                                        placeholder="Ej: 1,5"
                                    />
                                </FormField>

                                <FormField
                                    id={`details.${index}.unit_of_measure_id`}
                                    label="Unidad"
                                    required
                                    error={
                                        errors[
                                            `details.${index}.unit_of_measure_id`
                                        ]
                                    }
                                    className="md:col-span-3"
                                >
                                    {(control) => (
                                        <Select
                                            value={detail.unit_of_measure_id}
                                            onValueChange={(value) =>
                                                updateDetail(index, {
                                                    unit_of_measure_id: value,
                                                })
                                            }
                                        >
                                            <SelectTrigger {...control}>
                                                <SelectValue placeholder="Unidad..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(detail.kind === 'product'
                                                    ? massUnits
                                                    : units
                                                ).map((unit) => (
                                                    <SelectItem
                                                        key={unit.id}
                                                        value={String(unit.id)}
                                                    >
                                                        {unit.symbol}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </FormField>

                                {detail.kind === 'product' && (
                                    <FormField
                                        id={`details.${index}.costing_basis`}
                                        label="Se costea como"
                                        required
                                        help="Granel si se usa de un saldo o se tiñe en el mismo envase; una presentación si se vacían envases que no se reutilizan (su envase y etiqueta suman al precio)."
                                        error={
                                            errors[
                                                `details.${index}.costing_basis`
                                            ]
                                        }
                                        className="md:col-span-6 md:col-start-2"
                                    >
                                        {(control) => (
                                            <Select
                                                value={detail.costing}
                                                onValueChange={(value) =>
                                                    updateDetail(index, {
                                                        costing: value,
                                                    })
                                                }
                                                disabled={
                                                    detail.product_id === ''
                                                }
                                            >
                                                <SelectTrigger {...control}>
                                                    <SelectValue placeholder="Elige granel o una presentación..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {costingOptions(
                                                        detail.product_id,
                                                    ).map((option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}
                                    </FormField>
                                )}
                            </FormLineItem>
                        ))}
                    </div>

                    <FormError id="details" message={errors.details} />
                </CardContent>
            </Card>

            <FormActions
                submitLabel={submitLabel}
                processing={processing}
                cancelHref={cancelHref}
            />
        </form>
    );
}
