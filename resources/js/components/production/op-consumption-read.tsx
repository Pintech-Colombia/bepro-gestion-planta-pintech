import { DetailTable } from '@/components/detail-table';
import type { DetailTableColumn } from '@/components/detail-table';
import { FormattedNumber } from '@/components/formatted-number';
import Heading from '@/components/heading';
import type { ProductTableRow } from '@/components/production/ingredients-table';
import { LineAdjustmentsPanel } from '@/components/production/line-adjustments-panel';
import {
    excessLabel,
    OverconsumptionReasons,
} from '@/components/production/overconsumption-reasons';
import { ToneBadge } from '@/components/tone-badge';
import {
    materialCodesOf,
    materialReasonRows,
    orderByStep,
    productNamesOf,
    productReasonRows,
} from '@/lib/production-consumption';
import type {
    ProductionOrderConsumptionReason,
    ProductionOrderFormData,
    ProductionOrderIngredientFormRow,
    ProductionOrderLineAdjustment,
    ProductionOrderOverconsumption,
    ProductionOrderProductConsumptionReason,
    ProductionOrderProductOverconsumption,
    ProductionOrderSetData,
} from '@/types/production-orders';

/** Un renglón de la tabla: una materia prima o un producto terminado, en su paso. */
type ConsumptionRow = {
    key: string;
    step: number | null;
    name: string;
    isProduct: boolean;
    excess: string | null;
    planned: string | number | null;
    actual: string | number | null;
    unit: string | null;
    unitCost: string | number | null | undefined;
    totalCost: string | number | null | undefined;
};

type OpConsumptionReadProps = {
    orderId: number;
    data: ProductionOrderFormData;
    setData: ProductionOrderSetData;
    ingredientRows: ProductionOrderIngredientFormRow[];
    productRows: ProductTableRow[];
    lineAdjustments: ProductionOrderLineAdjustment[];
    isCompleted: boolean;
    showCosts: boolean;
    overconsumption: ProductionOrderOverconsumption[];
    consumptionReasons: ProductionOrderConsumptionReason[];
    productOverconsumption: ProductionOrderProductOverconsumption[];
    productConsumptionReasons: ProductionOrderProductConsumptionReason[];
};

/**
 * Consumo de una orden cerrada a la edición, como lectura: cada materia prima y cada producto terminado en su paso
 * (como en la OP de papel), los ajustes de línea y los motivos de sobreconsumo. De qué saldo o lote salió cada producto
 * va en el PDF y el Excel, no aquí (decisión del usuario, 2026-11-27).
 */
export function OpConsumptionRead({
    orderId,
    data,
    setData,
    ingredientRows,
    productRows,
    lineAdjustments,
    isCompleted,
    showCosts,
    overconsumption,
    consumptionReasons,
    productOverconsumption,
    productConsumptionReasons,
}: OpConsumptionReadProps) {
    const exceeding = new Map(
        overconsumption.map((row) => [row.raw_material_id, row]),
    );
    const exceedingProducts = new Map(
        productOverconsumption.map((row) => [row.product_id, row]),
    );

    // El mismo orden que la tabla de la OP abierta.
    const rows: ConsumptionRow[] = orderByStep<ConsumptionRow>(
        [
            ...ingredientRows.map((ingredient): ConsumptionRow => {
                const excess = exceeding.get(ingredient.raw_material_id);

                return {
                    key: `mp-${ingredient.id}`,
                    step: ingredient.step_order ?? null,
                    name:
                        ingredient.raw_material_name ??
                        `MP #${ingredient.raw_material_id}`,
                    isProduct: false,
                    excess: excess ? excessLabel(excess) : null,
                    planned:
                        ingredient.display_quantity ??
                        ingredient.planned_quantity,
                    actual: ingredient.actual_quantity,
                    unit:
                        ingredient.display_unit ||
                        ingredient.raw_material_unit ||
                        null,
                    unitCost: ingredient.unit_cost,
                    totalCost: ingredient.total_cost,
                };
            }),
            ...productRows.map(({ line, actualKg, unitCost, totalCost }) => {
                const excess = exceedingProducts.get(line.product_id);

                return {
                    key: `pt-${line.id}`,
                    step: line.step_order,
                    name: line.product_name ?? `PT #${line.product_id}`,
                    isProduct: true,
                    excess: excess ? excessLabel(excess) : null,
                    planned: line.planned_kg,
                    actual: actualKg,
                    unit: 'kg',
                    unitCost,
                    totalCost,
                };
            }),
        ],
        (row) => row.step,
    );

    const materialCodes = materialCodesOf(ingredientRows);
    const productNames = productNamesOf(productRows.map((row) => row.line));

    const quantity = (value: string | number | null, unit: string | null) =>
        value === null || value === '' ? null : (
            <>
                <FormattedNumber value={value} kind="quantity" />
                {unit && ` ${unit}`}
            </>
        );

    const columns: DetailTableColumn<ConsumptionRow>[] = [
        {
            key: 'step',
            header: 'Paso',
            cell: (row) => row.step,
            align: 'center',
            className: 'w-14 text-muted-foreground',
        },
        {
            key: 'name',
            header: 'Insumo',
            cell: (row) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    {row.name}
                    {row.isProduct && (
                        <ToneBadge title="Producto terminado">PT</ToneBadge>
                    )}
                    {row.excess && (
                        <ToneBadge
                            tone="warning"
                            title="Se pasó de lo planeado (todos sus pasos y ajustes)"
                        >
                            {row.excess}
                        </ToneBadge>
                    )}
                </span>
            ),
        },
        {
            key: 'planned',
            header: 'Planeado',
            cell: (row) => quantity(row.planned, row.unit),
            align: 'right',
            className: 'whitespace-nowrap text-muted-foreground',
        },
        {
            key: 'actual',
            header: 'Real gastado',
            cell: (row) => quantity(row.actual, row.unit),
            align: 'right',
            className: 'whitespace-nowrap',
        },
        ...(showCosts
            ? ([
                  {
                      key: 'unit_cost',
                      header: 'Costo unit.',
                      cell: (row) => (
                          <FormattedNumber
                              value={row.unitCost}
                              kind="unitCost"
                          />
                      ),
                      align: 'right',
                      className: 'text-muted-foreground',
                  },
                  {
                      key: 'total_cost',
                      header: 'Costo total',
                      cell: (row) => (
                          <FormattedNumber value={row.totalCost} kind="cost" />
                      ),
                      align: 'right',
                      className: 'font-medium',
                  },
              ] satisfies DetailTableColumn<ConsumptionRow>[])
            : []),
    ];

    return (
        // Una tabla de detalle no va dentro de una tarjeta (DESIGN.md §9): su título va encima.
        <section className="space-y-4">
            <Heading
                title="Consumo de materias primas"
                description="Lo real que se gastó en cada paso."
            />
            <DetailTable
                rows={rows}
                rowKey={(row) => row.key}
                columns={columns}
                empty="Esta orden no tiene insumos planificados."
                caption="Consumo de materias primas y productos terminados"
            />

            <LineAdjustmentsPanel
                orderId={orderId}
                adjustments={lineAdjustments}
                rawMaterials={[]}
                isCompleted={isCompleted}
                isReadOnly
                showForm={false}
            />

            <OverconsumptionReasons
                field="overconsumption_reasons"
                title="Consumo por encima de lo planeado"
                description="Por materia prima, sumando todos sus pasos y ajustes de línea."
                rows={materialReasonRows(overconsumption)}
                storedIds={consumptionReasons.map(
                    (reason) => reason.raw_material_id,
                )}
                labels={materialCodes}
                fallbackLabel={(id) => `MP #${id}`}
                data={data}
                setData={setData}
                errors={{}}
                isReadOnly
                showCosts={showCosts}
            />
            <OverconsumptionReasons
                field="product_overconsumption_reasons"
                title="Producto terminado por encima de lo planeado"
                description="Por producto, sumando todas sus líneas, en kilos."
                rows={productReasonRows(productOverconsumption)}
                storedIds={productConsumptionReasons.map(
                    (reason) => reason.product_id,
                )}
                labels={productNames}
                fallbackLabel={(id) => `PT #${id}`}
                data={data}
                setData={setData}
                errors={{}}
                isReadOnly
                showCosts={showCosts}
            />
        </section>
    );
}
