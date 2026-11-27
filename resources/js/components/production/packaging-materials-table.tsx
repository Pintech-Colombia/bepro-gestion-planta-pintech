import { IntegerInput } from '@/components/integer-input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    LabelMaterialOption,
    ProductionOrderPackagingFormRow,
} from '@/types/production-orders';

/** Valor del selector para «sin etiqueta»: un SelectItem no admite valor vacío. */
const NO_LABEL = 'none';

/**
 * Envase y etiqueta de cada presentación (3.7), en una tabla aparte de lo envasado: una fila por presentación y la
 * ayuda una sola vez. Vacío = tantos como unidades envasadas (el gris lo muestra); los envases reutilizados no se
 * descuentan ni cuestan. Sin tope: la merma (envase dañado, etiqueta mal pegada) es costo del lote. El `id` de cada
 * campo es la llave de su error, para que el foco llegue a él.
 */
export function PackagingMaterialsTable({
    rows,
    labelMaterials,
    isReadOnly,
    onChange,
}: {
    rows: ProductionOrderPackagingFormRow[];
    labelMaterials: LabelMaterialOption[];
    isReadOnly: boolean;
    onChange: (
        index: number,
        patch: Partial<ProductionOrderPackagingFormRow>,
    ) => void;
}) {
    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">
                Vacío = igual a lo envasado. Los envases reutilizados no se
                descuentan ni cuestan.
            </p>
            <div className="overflow-hidden rounded-md border">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="p-3 text-left">Presentación</th>
                                <th className="p-3 text-left">Envase</th>
                                <th className="w-36 p-3 text-right">
                                    Envases nuevos
                                </th>
                                <th className="w-48 p-3 text-left">Etiqueta</th>
                                <th className="w-36 p-3 text-right">
                                    Etiquetas usadas
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((pack, index) => {
                                // Una etiqueta inactiva solo se ofrece al plan que la tiene guardada, aunque el operario
                                // elija otra y quiera volver.
                                const labelOptions = labelMaterials.filter(
                                    (label) =>
                                        label.is_active ||
                                        label.id ===
                                            pack.saved_label_raw_material_id ||
                                        label.id === pack.label_raw_material_id,
                                );
                                const unitsPlaceholder = `= ${pack.actual_units || 0}`;

                                return (
                                    <tr
                                        key={pack.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 font-medium">
                                            {pack.presentation}
                                        </td>
                                        <td className="p-3 font-mono text-muted-foreground">
                                            {pack.package_code ?? 'Sin envase'}
                                        </td>
                                        <td className="p-3">
                                            <IntegerInput
                                                id={`packaging.${index}.new_containers_used`}
                                                aria-label={`Envases nuevos de ${pack.presentation}`}
                                                className="h-8 text-right"
                                                value={pack.new_containers_used}
                                                placeholder={unitsPlaceholder}
                                                onValueChange={(value) =>
                                                    onChange(index, {
                                                        new_containers_used:
                                                            value,
                                                    })
                                                }
                                                disabled={
                                                    isReadOnly ||
                                                    !pack.package_code
                                                }
                                            />
                                        </td>
                                        <td className="p-3">
                                            <Select
                                                value={
                                                    pack.label_raw_material_id !=
                                                    null
                                                        ? String(
                                                              pack.label_raw_material_id,
                                                          )
                                                        : NO_LABEL
                                                }
                                                onValueChange={(value) =>
                                                    // Sin etiqueta tampoco hay etiquetas usadas: el campo se
                                                    // deshabilita y se vacía.
                                                    onChange(
                                                        index,
                                                        value === NO_LABEL
                                                            ? {
                                                                  label_raw_material_id:
                                                                      null,
                                                                  labels_used:
                                                                      '',
                                                              }
                                                            : {
                                                                  label_raw_material_id:
                                                                      Number(
                                                                          value,
                                                                      ),
                                                              },
                                                    )
                                                }
                                                disabled={isReadOnly}
                                            >
                                                <SelectTrigger
                                                    id={`packaging.${index}.label_raw_material_id`}
                                                    aria-label={`Etiqueta de ${pack.presentation}`}
                                                    className="h-8"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem
                                                        value={NO_LABEL}
                                                    >
                                                        Sin etiqueta
                                                    </SelectItem>
                                                    {labelOptions.map(
                                                        (label) => (
                                                            <SelectItem
                                                                key={label.id}
                                                                value={String(
                                                                    label.id,
                                                                )}
                                                            >
                                                                {label.is_active
                                                                    ? label.code
                                                                    : `${label.code} (inactiva)`}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </td>
                                        <td className="p-3">
                                            <IntegerInput
                                                id={`packaging.${index}.labels_used`}
                                                aria-label={`Etiquetas usadas de ${pack.presentation}`}
                                                className="h-8 text-right"
                                                value={pack.labels_used}
                                                placeholder={unitsPlaceholder}
                                                onValueChange={(value) =>
                                                    onChange(index, {
                                                        labels_used: value,
                                                    })
                                                }
                                                disabled={
                                                    isReadOnly ||
                                                    pack.label_raw_material_id ==
                                                        null
                                                }
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
