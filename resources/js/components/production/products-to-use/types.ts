import type {
    ProductionOrder,
    ProductionOrderBatchUsageRow,
    ProductionOrderProductLineUsageRow,
} from '@/types/production-orders';

/** Lo que recibe «Productos a usar»; sus piezas toman de aquí lo que necesitan. */
export type ProductsToUseCardProps = {
    order: ProductionOrder;
    /** Agregar, cambiar y quitar fuentes (`can.planRemnant`). */
    canPlan: boolean;
    /** Los costos, solo con costs.view. */
    showCosts: boolean;
    /** Se escribe lo que quedó: la orden se está entregando (enviar a revisión o completar). */
    canRecordUsage: boolean;
    usageRows: ProductionOrderBatchUsageRow[];
    /** Lo real gastado de cada producto, tal como se escribe en la tabla de consumo: para la cuenta en unidades y el aviso. */
    lineUsageRows: ProductionOrderProductLineUsageRow[];
    onUsageChange: (
        index: number,
        patch: Partial<Omit<ProductionOrderBatchUsageRow, 'id'>>,
    ) => void;
    /** Los errores del formulario de la orden (enviar o completar). */
    errors: Record<string, string | undefined>;
    /** El costo de cada lote según la vista previa, con lo que quedó tal como se escribe. */
    previewProductCosts: Map<number, string | null> | null;
};
