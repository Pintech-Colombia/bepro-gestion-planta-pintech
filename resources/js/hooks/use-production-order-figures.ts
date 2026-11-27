import { useMemo } from 'react';

import { formatForInput } from '@/lib/formatters';
import { parseDecimal } from '@/lib/numeric-input';
import {
    mapDetailToIngredientFormRow,
    mapPackagingPlanToFormRow,
} from '@/lib/production-order-form';
import { remnantGallonsForDisplay } from '@/lib/remnant-quantity';
import type {
    PreviewCostData,
    ProductionOrder,
    ProductionOrderFormData,
    ProductTableRow,
} from '@/types/production-orders';

type UseProductionOrderFiguresProps = {
    order: ProductionOrder;
    data: ProductionOrderFormData;
    previewCosts: PreviewCostData | null;
    isPending: boolean;
    isCompleted: boolean;
    isCancelled: boolean;
    isFormReadOnly: boolean;
};

/**
 * Las filas y cifras que muestra la OP: con la orden abierta, lo digitado con los costos de la vista previa; cerrada a
 * la edición, lo guardado. Solo para mostrar: lo que se guarda lo calcula el servidor.
 */
export function useProductionOrderFigures({
    order,
    data,
    previewCosts,
    isPending,
    isCompleted,
    isCancelled,
    isFormReadOnly,
}: UseProductionOrderFiguresProps) {
    const previewIngredientsById = useMemo(() => {
        if (!previewCosts) {
            return new Map<number, PreviewCostData['ingredients'][number]>();
        }

        return new Map(
            previewCosts.ingredients.map((ingredient) => [
                ingredient.id,
                ingredient,
            ]),
        );
    }, [previewCosts]);

    // Una sola cuenta, la del servidor: la vista previa mientras se digita y, si aún no llega (o la orden está
    // completada), el resumen que trajo la página. Cancelada: null.
    const costSummary =
        isCompleted || isCancelled
            ? (order.cost_summary ?? null)
            : (previewCosts?.summary ?? order.cost_summary ?? null);

    // El costo de cada lote con lo que quedó tal como se escribe; completada o cancelada, el de la página.
    const previewProductCosts = useMemo(
        () =>
            isCompleted || isCancelled || !previewCosts
                ? null
                : new Map(
                      (previewCosts.products ?? []).map((product) => [
                          product.id,
                          product.cost,
                      ]),
                  ),
        [isCompleted, isCancelled, previewCosts],
    );

    // El costo de cada producto (renglón de la tabla) con lo que quedó tal como se escribe; si no, el de la página.
    const previewProductLineCosts = useMemo(
        () =>
            isCompleted || isCancelled || !previewCosts
                ? null
                : new Map(
                      (previewCosts.product_lines ?? []).map((line) => [
                          line.id,
                          line,
                      ]),
                  ),
        [isCompleted, isCancelled, previewCosts],
    );

    // Cada producto como un renglón más de la tabla de consumo, en su paso; de dónde sale está en «Productos a usar».
    const productRows: ProductTableRow[] = (order.product_lines ?? []).map(
        (line) => {
            const index = data.product_lines.findIndex(
                (row) => row.id === line.id,
            );
            const previewLine = previewProductLineCosts?.get(line.id);

            return {
                line,
                index,
                actualKg:
                    isFormReadOnly || index < 0
                        ? formatForInput(line.actual_kg)
                        : data.product_lines[index].actual_kg,
                unitCost: previewLine ? previewLine.unit_cost : line.unit_cost,
                totalCost: previewLine ? previewLine.cost : line.cost,
            };
        },
    );

    const packagingCosts = useMemo(
        () =>
            new Map(
                (costSummary?.packaging ?? []).map((pack) => [pack.id, pack]),
            ),
        [costSummary],
    );

    const ingredientRows = isFormReadOnly
        ? (order.details ?? []).map(mapDetailToIngredientFormRow)
        : data.ingredients.map((ingredient) => ({
              ...ingredient,
              unit_cost:
                  previewIngredientsById.get(ingredient.id)?.unit_cost ??
                  ingredient.unit_cost ??
                  0,
              total_cost:
                  previewIngredientsById.get(ingredient.id)?.total_cost ??
                  ingredient.total_cost ??
                  0,
          }));

    const packagingRows = isFormReadOnly
        ? (order.packaging_plans ?? []).map((pack) =>
              mapPackagingPlanToFormRow(pack),
          )
        : data.packaging;

    // Con la orden cerrada a la edición se muestra el rendimiento guardado. Lo digitado se lee con parseDecimal, como en
    // el resto de los formularios: llega como texto y con coma («2,5»). Pendiente, nada se ha producido: las filas
    // traen lo planeado.
    const packagedEquivalent = isPending
        ? null
        : packagingRows.reduce(
              (sum, pack) =>
                  sum +
                  (parseDecimal(pack.actual_units) ?? 0) *
                      (parseDecimal(pack.presentation_value) ?? 0),
              0,
          );
    const remnantGallons = isFormReadOnly
        ? (parseDecimal(order.remnant_quantity_gallons) ?? 0)
        : (remnantGallonsForDisplay(
              data.remnant_quantity_kg,
              data.density_kg_per_gallon,
          ) ?? 0);
    const realYield =
        packagedEquivalent === null
            ? null
            : isFormReadOnly && order.actual_quantity != null
              ? (parseDecimal(order.actual_quantity) ?? 0)
              : packagedEquivalent + remnantGallons;
    const plannedQuantity = parseDecimal(order.quantity) ?? 0;
    const yieldPercentage =
        realYield !== null && plannedQuantity > 0
            ? (realYield / plannedQuantity) * 100
            : null;

    return {
        costSummary,
        previewProductCosts,
        productRows,
        packagingCosts,
        ingredientRows,
        packagingRows,
        packagedEquivalent,
        remnantGallons,
        realYield,
        yieldPercentage,
    };
}
