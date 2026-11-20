import { useEffect, useMemo, useRef } from 'react';

import { formatForInput } from '@/lib/formatters';
import type {
    ProductionOrderBatchConsumption,
    ProductionOrderBatchUsageRow,
    ProductionOrderSetData,
} from '@/types/production-orders';

/**
 * Lo que se escribe de un lote a partir de lo guardado (al enviar a revisión): quien aprueba lo ve y lo corrige. Lo que
 * el operario ya escribió (`edited`) se conserva. Una sola fuente para el estado inicial de la página y para la
 * sincronización al agregar o quitar lotes.
 */
export function mapBatchSourceToUsageRow(
    source: ProductionOrderBatchConsumption,
    edited?: ProductionOrderBatchUsageRow,
): ProductionOrderBatchUsageRow {
    if (edited) {
        return edited;
    }

    return {
        id: source.id,
        used_kg: formatForInput(source.used_kg),
        has_leftover: source.leftover_kg !== null,
        leftover_kg: formatForInput(source.leftover_kg),
    };
}

type UseBatchUsageSyncProps = {
    sources: ProductionOrderBatchConsumption[];
    currentRows: ProductionOrderBatchUsageRow[];
    setData: ProductionOrderSetData;
};

/**
 * Agregar o quitar un lote recarga la orden, pero el formulario se armó con los lotes de antes: aquí se rehace la lista
 * con los de ahora, en su orden, sin perder lo escrito en los que siguen.
 */
export function useBatchUsageSync({
    sources,
    currentRows,
    setData,
}: UseBatchUsageSyncProps) {
    const currentRowsRef = useRef(currentRows);
    const sourceIds = useMemo(
        () => sources.map((source) => source.id).join(','),
        [sources],
    );

    useEffect(() => {
        currentRowsRef.current = currentRows;
    }, [currentRows]);

    useEffect(() => {
        const current = currentRowsRef.current;

        if (current.map((row) => row.id).join(',') === sourceIds) {
            return;
        }

        setData(
            'batch_consumptions',
            sources.map((source) =>
                mapBatchSourceToUsageRow(
                    source,
                    current.find((row) => row.id === source.id),
                ),
            ),
        );
    }, [sourceIds, sources, setData]);
}
