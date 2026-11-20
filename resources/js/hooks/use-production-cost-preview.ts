import { previewCosts as productionOrderPreviewCosts } from '@/actions/App/Http/Controllers/ProductionOrderController';
import { useServerPreview } from '@/hooks/use-server-preview';
import type { ServerPreviewRequest } from '@/hooks/use-server-preview';
import { parseDecimal, toDecimalPayload } from '@/lib/numeric-input';
import type {
    FormNumberValue,
    PreviewCostData,
    ProductionOrderBatchUsageRow,
    ProductionOrderIngredientFormRow,
    ProductionOrderLineAdjustment,
    ProductionOrderPackagingFormRow,
} from '@/types/production-orders';

/** Espera tras la última tecla antes de pedir los costos. */
const PREVIEW_DELAY_MS = 1000;

type UseProductionCostPreviewProps = {
    orderId: number;
    ingredients: ProductionOrderIngredientFormRow[];
    packaging: ProductionOrderPackagingFormRow[];
    lineAdjustments: ProductionOrderLineAdjustment[];
    remnantQuantityKg: FormNumberValue;
    density: FormNumberValue;
    /** Lo que quedó en cada envase abierto, tal como se escribe: el costo de la pantalla es el de completar. */
    batchUsage: ProductionOrderBatchUsageRow[];
    /**
     * Las fuentes de «Productos a usar» (saldos y lotes, con sus kilos y unidades). No viajan (el servidor las lee de la
     * OP), pero al agregar, quitar o cambiar una piden otra vista previa.
     */
    sourcesKey: string;
    isCompleted: boolean;
    enabled?: boolean;
};

type PreviewCostPayload = {
    ingredients: Array<{ id: number; actual_quantity: string }>;
    packaging: Array<{
        id: number;
        actual_units: number;
        new_containers_used: string | null;
        label_raw_material_id: number | null;
        labels_used: string | null;
    }>;
    remnant_quantity_kg: string;
    density_kg_per_gallon: string | null;
    batch_consumptions: Array<{
        id: number;
        has_leftover: boolean;
        leftover_kg: string | null;
    }>;
};

/** Los ajustes de línea y las fuentes no viajan (el servidor los lee de la OP), pero al cambiar piden otra vista previa. */
type PreviewContext = {
    lineAdjustments: Array<{ id: number; quantity: number }>;
    sources: string;
};

export function useProductionCostPreview({
    orderId,
    ingredients,
    packaging,
    lineAdjustments,
    remnantQuantityKg,
    density,
    batchUsage,
    sourcesKey,
    isCompleted,
    enabled = true,
}: UseProductionCostPreviewProps) {
    const request: ServerPreviewRequest<
        PreviewCostPayload,
        PreviewContext
    > | null =
        isCompleted || !enabled
            ? null
            : {
                  url: productionOrderPreviewCosts({
                      production_order: orderId,
                  }).url,
                  body: {
                      ingredients: ingredients.map((ingredient) => ({
                          id: ingredient.id,
                          // Lo digitado, como texto: el servidor lo convierte y calcula con bcmath.
                          actual_quantity:
                              toDecimalPayload(ingredient.actual_quantity) ||
                              '0',
                      })),
                      packaging: packaging.map((pack) => ({
                          id: pack.id,
                          actual_units: parseDecimal(pack.actual_units) ?? 0,
                          // Como texto: el servidor los calcula con bcmath. Vacío = tantos como unidades envasadas.
                          new_containers_used:
                              pack.new_containers_used === ''
                                  ? null
                                  : String(pack.new_containers_used),
                          label_raw_material_id: pack.label_raw_material_id,
                          labels_used:
                              pack.labels_used === ''
                                  ? null
                                  : String(pack.labels_used),
                      })),
                      // Como texto: el servidor calcula los galones con bcmath. Sin peso por galón, calcula sin sobrante.
                      remnant_quantity_kg:
                          toDecimalPayload(remnantQuantityKg) || '0',
                      // Lo digitado, para el costo por kg y los galones del sobrante; vacío o no positivo va como null
                      // (el servidor usa el guardado) para que no invalide toda la vista previa.
                      density_kg_per_gallon:
                          (parseDecimal(density) ?? 0) > 0
                              ? toDecimalPayload(density) || null
                              : null,
                      // Lo que quedó solo cuenta con «sí» y un peso mayor a 0; a medio escribir, como si no quedara nada
                      // (completar sí lo exige).
                      batch_consumptions: batchUsage.map((row) => ({
                          id: row.id,
                          has_leftover: row.has_leftover,
                          leftover_kg:
                              row.has_leftover &&
                              (parseDecimal(row.leftover_kg) ?? 0) > 0
                                  ? toDecimalPayload(row.leftover_kg)
                                  : null,
                      })),
                  },
                  context: {
                      lineAdjustments: lineAdjustments.map((adjustment) => ({
                          id: adjustment.id,
                          quantity: Number(adjustment.quantity) || 0,
                      })),
                      sources: sourcesKey,
                  },
              };

    const { result, loading, stale } = useServerPreview<
        PreviewCostData,
        PreviewCostPayload,
        PreviewContext
    >(request, PREVIEW_DELAY_MS);

    return {
        previewCosts: result?.value ?? null,
        previewLoading: loading,
        previewStale: stale,
    };
}
