import { useForm } from '@inertiajs/react';

import {
    store,
    update,
} from '@/actions/App/Http/Controllers/ProductVariantController';
import { DecimalInput } from '@/components/decimal-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PRESENTATION_VALUE_LIMITS } from '@/lib/number-types';
import { toDecimalPayload } from '@/lib/numeric-input';

/** Presentación (variante) de un producto, tal como la envía la ficha. */
export type ProductVariantItem = {
    id: number;
    code: string;
    unit_of_measure_id: number;
    presentation_value: string | number | null;
    presentation_label: string;
    current_cost?: string | number | null;
    current_price?: string | null;
    package_raw_material_id: number | null;
    label_raw_material_id: number | null;
    is_active: boolean;
    unit_of_measure?: { name: string; symbol: string } | null;
};

export type VariantUnitOption = {
    id: number;
    name: string;
    symbol: string;
    is_active: boolean;
};

export type VariantPackageOption = {
    id: number;
    code: string;
    is_active: boolean;
    category?: { id: number; name: string };
};

export type VariantLabelOption = {
    id: number;
    code: string;
    is_active: boolean;
};

type VariantFormDialogProps = {
    productId: number;
    open: boolean;
    /** Presentación que se edita; `null` para crear una nueva. */
    variant: ProductVariantItem | null;
    onClose: () => void;
    canViewCosts: boolean;
    hasPriceReferenceFormula: boolean;
    units: VariantUnitOption[];
    rawMaterials?: VariantPackageOption[];
    labelMaterials?: VariantLabelOption[];
};

/** Valor del selector para «sin etiqueta»: un SelectItem no admite valor vacío. */
const NO_LABEL = 'none';

const EMPTY_VARIANT_FORM = {
    code: '',
    unit_of_measure_id: '',
    presentation_value: '',
    presentation_label: '',
    package_raw_material_id: '',
    label_raw_material_id: '',
    is_active: true,
};

function variantFormData(variant: ProductVariantItem) {
    return {
        code: variant.code,
        unit_of_measure_id: String(variant.unit_of_measure_id),
        presentation_value:
            variant.presentation_value != null
                ? String(variant.presentation_value)
                : '',
        presentation_label: variant.presentation_label,
        package_raw_material_id:
            variant.package_raw_material_id != null
                ? String(variant.package_raw_material_id)
                : '',
        label_raw_material_id:
            variant.label_raw_material_id != null
                ? String(variant.label_raw_material_id)
                : '',
        is_active: variant.is_active,
    };
}

/**
 * Diálogo de crear o editar una presentación del producto. Quien lo abre lo vuelve a montar (con `key`) en cada
 * apertura, así el formulario arranca con los datos de la presentación elegida o vacío.
 */
export function VariantFormDialog({
    productId,
    open,
    variant,
    onClose,
    canViewCosts,
    hasPriceReferenceFormula,
    units,
    rawMaterials,
    labelMaterials,
}: VariantFormDialogProps) {
    const dialogMode = variant ? 'edit' : 'create';
    const form = useForm(
        variant ? variantFormData(variant) : EMPTY_VARIANT_FORM,
    );

    // Un envase inactivo solo se ofrece a la presentación que ya lo usa.
    const keptPackageId = variant?.package_raw_material_id;
    const packageOptions = (rawMaterials ?? []).filter(
        (rm) => rm.is_active || rm.id === keptPackageId,
    );

    // Igual con la etiqueta habitual.
    const keptLabelId = variant?.label_raw_material_id;
    const labelOptions = (labelMaterials ?? []).filter(
        (rm) => rm.is_active || rm.id === keptLabelId,
    );

    // Igual con la unidad: una inactiva solo se ofrece a la presentación que ya la usa.
    const keptUnitId = variant?.unit_of_measure_id;
    const unitOptions = units.filter((u) => u.is_active || u.id === keptUnitId);

    const close = () => {
        onClose();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            presentation_value: toDecimalPayload(data.presentation_value),
        }));

        if (variant) {
            form.patch(
                update({ product: productId, variant: variant.id }).url,
                {
                    onSuccess: () => {
                        close();
                    },
                },
            );
        } else {
            form.post(store({ product: productId }).url, {
                onSuccess: () => {
                    close();
                },
            });
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(isOpen) => {
                if (!isOpen) {
                    close();
                }
            }}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {dialogMode === 'edit'
                            ? 'Editar presentación'
                            : 'Nueva presentación'}
                    </DialogTitle>
                </DialogHeader>
                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="presentation_label">
                                Presentación
                                <span
                                    className="ml-0.5 text-destructive"
                                    aria-hidden="true"
                                >
                                    *
                                </span>
                            </Label>
                            <Input
                                id="presentation_label"
                                value={form.data.presentation_label}
                                onChange={(e) =>
                                    form.setData(
                                        'presentation_label',
                                        e.target.value,
                                    )
                                }
                                maxLength={50}
                                aria-required
                                aria-invalid={!!form.errors.presentation_label}
                                placeholder="Ej: Galón, Cuñete 5G"
                            />
                            <InputError
                                message={form.errors.presentation_label}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="code">
                                Código
                                <span
                                    className="ml-0.5 text-destructive"
                                    aria-hidden="true"
                                >
                                    *
                                </span>
                            </Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                maxLength={80}
                                aria-required
                                aria-invalid={!!form.errors.code}
                                className="uppercase placeholder:normal-case"
                                placeholder="Ej: 77709808"
                            />
                            {form.errors.code && (
                                <p className="text-xs text-destructive">
                                    {form.errors.code}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="unit_of_measure_id">
                                Unidad de Medida
                                <span
                                    className="ml-0.5 text-destructive"
                                    aria-hidden="true"
                                >
                                    *
                                </span>
                            </Label>
                            <Select
                                value={form.data.unit_of_measure_id}
                                onValueChange={(v) =>
                                    form.setData('unit_of_measure_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="unit_of_measure_id"
                                    aria-required
                                    aria-invalid={
                                        !!form.errors.unit_of_measure_id
                                    }
                                >
                                    <SelectValue placeholder="Selecciona..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {unitOptions.map((u) => (
                                        <SelectItem
                                            key={u.id}
                                            value={String(u.id)}
                                        >
                                            {u.name} ({u.symbol})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.unit_of_measure_id && (
                                <p className="text-xs text-destructive">
                                    {form.errors.unit_of_measure_id}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="presentation_value">
                                Valor Presentación (en galones)
                            </Label>
                            <DecimalInput
                                id="presentation_value"
                                {...PRESENTATION_VALUE_LIMITS}
                                value={form.data.presentation_value}
                                onValueChange={(value) =>
                                    form.setData('presentation_value', value)
                                }
                                placeholder="Ej: 1, 3, 5 o 0,25"
                            />
                            <p className="text-xs text-muted-foreground">
                                Ejemplos: 1 = Galón, 3 = Bidón 3gal, 5 = Cuñete
                                5gal, 0,25 = 1/4 galón
                            </p>
                            <InputError
                                message={form.errors.presentation_value}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {canViewCosts && (
                            <div className="space-y-2">
                                <Label htmlFor="current_cost">
                                    Costo Actual
                                </Label>
                                <Input
                                    id="current_cost"
                                    value={
                                        variant?.current_cost != null
                                            ? String(variant.current_cost)
                                            : ''
                                    }
                                    readOnly
                                    className="cursor-not-allowed bg-muted/50"
                                    placeholder="0.00"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {hasPriceReferenceFormula
                                        ? 'Calculado por la fórmula de referencia de precio, el envase y la etiqueta.'
                                        : 'Se calculará al registrar la fórmula de producción.'}
                                </p>
                            </div>
                        )}
                        {canViewCosts && (
                            <div className="space-y-2">
                                <Label htmlFor="current_price">
                                    Precio Interno
                                </Label>
                                <Input
                                    id="current_price"
                                    value={
                                        variant?.current_price != null
                                            ? String(variant.current_price)
                                            : ''
                                    }
                                    readOnly
                                    className="cursor-not-allowed bg-muted/50"
                                    placeholder="0.00"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {hasPriceReferenceFormula
                                        ? 'Calculado con el factor CIF al guardar.'
                                        : 'Se calculará al registrar la fórmula de producción.'}
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="package_raw_material_id">
                            Envase / Contenedor
                        </Label>
                        <Select
                            value={form.data.package_raw_material_id}
                            onValueChange={(v) =>
                                form.setData('package_raw_material_id', v)
                            }
                        >
                            <SelectTrigger id="package_raw_material_id">
                                <SelectValue placeholder="Selecciona el envase (opcional)" />
                            </SelectTrigger>
                            <SelectContent>
                                {packageOptions.map((rm) => (
                                    <SelectItem
                                        key={rm.id}
                                        value={String(rm.id)}
                                    >
                                        {rm.is_active
                                            ? rm.code
                                            : `${rm.code} (inactivo)`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Se descontará del inventario al completar la orden
                            de producción
                        </p>
                        <InputError
                            message={form.errors.package_raw_material_id}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="label_raw_material_id">
                            Etiqueta habitual
                        </Label>
                        <Select
                            value={form.data.label_raw_material_id || NO_LABEL}
                            onValueChange={(v) =>
                                form.setData(
                                    'label_raw_material_id',
                                    v === NO_LABEL ? '' : v,
                                )
                            }
                        >
                            <SelectTrigger id="label_raw_material_id">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_LABEL}>
                                    Sin etiqueta
                                </SelectItem>
                                {labelOptions.map((rm) => (
                                    <SelectItem
                                        key={rm.id}
                                        value={String(rm.id)}
                                    >
                                        {rm.is_active
                                            ? rm.code
                                            : `${rm.code} (inactiva)`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            La orden de producción la propone al envasar; su
                            precio entra en el costo de la presentación
                        </p>
                        <InputError
                            message={form.errors.label_raw_material_id}
                        />
                    </div>

                    {dialogMode === 'edit' && (
                        <div className="space-y-1">
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="variant_is_active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_active',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label htmlFor="variant_is_active">
                                    Presentación activa
                                </Label>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Una presentación inactiva no aparece en órdenes,
                                cotizaciones ni pedidos nuevos; su historial se
                                conserva.
                            </p>
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-4">
                        <Button type="button" variant="outline" onClick={close}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Guardando...'
                                : dialogMode === 'edit'
                                  ? 'Guardar cambios'
                                  : 'Guardar presentación'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
