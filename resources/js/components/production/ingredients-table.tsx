import { Fragment } from 'react';

import { DecimalInput } from '@/components/decimal-input';
import { FormError } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { excessLabel } from '@/components/production/overconsumption-reasons';
import { orderByStep } from '@/lib/production-consumption';
import { pillClasses, toneClasses } from '@/lib/tones';
import type {
    ProductionOrderFormData,
    ProductionOrderIngredientFormRow,
    ProductionOrderOverconsumption,
    ProductionOrderProductLine,
    ProductionOrderProductOverconsumption,
    ProductionOrderSetData,
} from '@/types/production-orders';

/** El renglón de un producto terminado que la orden lleva como ingrediente: se gasta en kilos, como una MP. */
export type ProductTableRow = {
    line: ProductionOrderProductLine;
    /** Su posición en `product_lines` del formulario (la de sus llaves de error); -1 mientras se sincroniza. */
    index: number;
    actualKg: string | number | null;
    unitCost: string | null | undefined;
    totalCost: string | null | undefined;
};

type TableRow =
    | {
          kind: 'material';
          step: number | null;
          ingredient: ProductionOrderIngredientFormRow;
          index: number;
      }
    | { kind: 'product'; step: number | null; product: ProductTableRow };

type IngredientsTableProps = {
    rows: ProductionOrderIngredientFormRow[];
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    isReadOnly: boolean;
    showCosts?: boolean;
    /** Las materias primas que se pasan (lo dice el servidor), por id: se marcan en cada una de sus líneas. */
    overconsumption?: ProductionOrderOverconsumption[];
    /** Los productos que se pasan, por id: se marcan en cada una de sus líneas. */
    productOverconsumption?: ProductionOrderProductOverconsumption[];
    /** Los productos que lleva la orden, cada uno en su paso; los agregados en la orden, al final. */
    productRows?: ProductTableRow[];
    onProductActualChange?: (index: number, value: string) => void;
    errors?: Record<string, string | undefined>;
};

export function IngredientsTable({
    rows,
    data,
    setData,
    isReadOnly,
    showCosts = true,
    overconsumption = [],
    productOverconsumption = [],
    productRows = [],
    onProductActualChange,
    errors = {},
}: IngredientsTableProps) {
    const exceeding = new Map(
        overconsumption.map((row) => [row.raw_material_id, row]),
    );
    const exceedingProducts = new Map(
        productOverconsumption.map((row) => [row.product_id, row]),
    );
    // Por paso y, dentro del paso, la materia prima primero (el mismo orden de la ficha de lectura).
    const tableRows: TableRow[] = orderByStep(
        [
            ...rows.map((ingredient, index): TableRow => ({
                kind: 'material',
                step: ingredient.step_order ?? null,
                ingredient,
                index,
            })),
            ...productRows.map((product): TableRow => ({
                kind: 'product',
                step: product.line.step_order,
                product,
            })),
        ],
        (row) => row.step,
    );

    return (
        <div className="overflow-hidden rounded-md border">
            <div className="relative overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b bg-muted/50">
                        <tr>
                            <th className="w-14 p-3 text-center">Paso</th>
                            <th className="p-3 text-left">Insumo</th>
                            <th className="p-3 text-right">Planeado</th>
                            <th className="w-40 p-3 text-right">
                                Real Gastado
                            </th>
                            {showCosts && (
                                <>
                                    <th className="p-3 text-right">
                                        Costo Unit.
                                    </th>
                                    <th className="p-3 text-right">
                                        Costo Total
                                    </th>
                                </>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {tableRows.map((tableRow) => {
                            if (tableRow.kind === 'product') {
                                return (
                                    <ProductRow
                                        key={`pt-${tableRow.product.line.id}`}
                                        product={tableRow.product}
                                        exceeding={exceedingProducts.get(
                                            tableRow.product.line.product_id,
                                        )}
                                        isReadOnly={isReadOnly}
                                        showCosts={showCosts}
                                        onActualChange={onProductActualChange}
                                        error={
                                            errors[
                                                `product_lines.${tableRow.product.index}.actual_kg`
                                            ]
                                        }
                                    />
                                );
                            }

                            const { ingredient, index } = tableRow;

                            return (
                                <tr
                                    key={ingredient.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="p-3 text-center text-muted-foreground tabular-nums">
                                        {ingredient.step_order ?? '—'}
                                    </td>
                                    <td className="p-3 font-medium">
                                        <span className="flex flex-wrap items-center gap-1.5">
                                            {ingredient.raw_material_name}
                                            {exceeding.has(
                                                ingredient.raw_material_id,
                                            ) && (
                                                <span
                                                    className={`${pillClasses} ${toneClasses.warning}`}
                                                    title="Se pasó de lo planeado (todos sus pasos y ajustes)"
                                                >
                                                    {excessLabel(
                                                        exceeding.get(
                                                            ingredient.raw_material_id,
                                                        )!,
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap text-muted-foreground">
                                        <FormattedNumber
                                            value={
                                                ingredient.display_quantity ??
                                                ingredient.planned_quantity
                                            }
                                            maxDecimals={4}
                                        />
                                        {ingredient.display_unit
                                            ? ` ${ingredient.display_unit}`
                                            : ingredient.raw_material_unit
                                              ? ` ${ingredient.raw_material_unit}`
                                              : ''}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex items-center justify-end gap-1.5">
                                            <DecimalInput
                                                className="h-8 w-28 text-right"
                                                value={
                                                    ingredient.actual_quantity
                                                }
                                                onValueChange={(value) => {
                                                    const newIngredients = [
                                                        ...data.ingredients,
                                                    ];
                                                    newIngredients[index] = {
                                                        ...newIngredients[
                                                            index
                                                        ],
                                                        actual_quantity: value,
                                                    };
                                                    setData(
                                                        'ingredients',
                                                        newIngredients,
                                                    );
                                                }}
                                                disabled={isReadOnly}
                                            />
                                            {(ingredient.display_unit ||
                                                ingredient.raw_material_unit) && (
                                                <span className="w-6 shrink-0 text-left text-xs text-muted-foreground">
                                                    {ingredient.display_unit ||
                                                        ingredient.raw_material_unit}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    {showCosts && (
                                        <>
                                            <td className="p-3 text-right text-muted-foreground">
                                                <FormattedNumber
                                                    value={ingredient.unit_cost}
                                                    kind="unitCost"
                                                />
                                            </td>
                                            <td className="p-3 text-right font-medium">
                                                <FormattedNumber
                                                    value={
                                                        ingredient.total_cost
                                                    }
                                                    kind="cost"
                                                />
                                            </td>
                                        </>
                                    )}
                                </tr>
                            );
                        })}
                        {tableRows.length === 0 && (
                            <tr>
                                <td
                                    className="p-3 text-muted-foreground"
                                    colSpan={showCosts ? 6 : 4}
                                >
                                    Esta orden no tiene insumos planificados.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function ProductRow({
    product,
    exceeding,
    isReadOnly,
    showCosts,
    onActualChange,
    error,
}: {
    product: ProductTableRow;
    /** Si el producto se pasó de lo planeado (todas sus líneas), con el exceso. */
    exceeding?: ProductionOrderProductOverconsumption;
    isReadOnly: boolean;
    showCosts: boolean;
    onActualChange?: (index: number, value: string) => void;
    error: string | undefined;
}) {
    const { line } = product;
    const inputId = `product-line-${line.id}-actual`;
    // La llave del error del servidor: el foco llega aquí ante un 422.
    const errorKey = `product_lines.${product.index}.actual_kg`;

    return (
        <Fragment>
            <tr className="border-b last:border-0">
                <td className="p-3 text-center align-top text-muted-foreground tabular-nums">
                    {line.step_order ?? '—'}
                </td>
                <td className="p-3 align-top">
                    <span className="flex flex-wrap items-center gap-1.5 font-medium">
                        {line.product_name}
                        <span
                            className={`${pillClasses} ${toneClasses.neutral}`}
                            title="Producto terminado: sale de un saldo o de un lote"
                        >
                            PT
                        </span>
                        {exceeding && (
                            <span
                                className={`${pillClasses} ${toneClasses.warning}`}
                                title="Se pasó de lo planeado (todas sus líneas)"
                            >
                                {excessLabel(exceeding)}
                            </span>
                        )}
                    </span>
                </td>
                <td className="p-3 text-right align-top whitespace-nowrap text-muted-foreground">
                    {line.planned_kg !== null ? (
                        <>
                            <FormattedNumber
                                value={line.planned_kg}
                                maxDecimals={4}
                            />{' '}
                            kg
                        </>
                    ) : (
                        '—'
                    )}
                </td>
                <td className="p-3 align-top">
                    <div className="flex items-center justify-end gap-1.5">
                        <DecimalInput
                            id={inputId}
                            className="h-8 w-28 text-right"
                            value={product.actualKg ?? ''}
                            onValueChange={(value) =>
                                product.index >= 0 &&
                                onActualChange?.(product.index, value)
                            }
                            disabled={isReadOnly || product.index < 0}
                            aria-invalid={error !== undefined}
                            aria-describedby={
                                error !== undefined ? errorKey : undefined
                            }
                        />
                        <span className="w-6 shrink-0 text-left text-xs text-muted-foreground">
                            kg
                        </span>
                    </div>
                    <FormError
                        id={errorKey}
                        message={error}
                        className="text-right"
                    />
                </td>
                {showCosts && (
                    <>
                        <td className="p-3 text-right align-top text-muted-foreground">
                            <FormattedNumber
                                value={product.unitCost}
                                kind="unitCost"
                            />
                        </td>
                        <td className="p-3 text-right align-top font-medium">
                            <FormattedNumber
                                value={product.totalCost}
                                kind="cost"
                            />
                        </td>
                    </>
                )}
            </tr>
        </Fragment>
    );
}
