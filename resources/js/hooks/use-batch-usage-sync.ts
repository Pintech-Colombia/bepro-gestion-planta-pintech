import { useEffect, useMemo, useRef } from 'react';

import {
    mapBatchSourceToUsageRow,
    mapProductLineToUsageRow,
} from '@/lib/production-order-form';
import type {
    ProductionOrderBatchConsumption,
    ProductionOrderBatchUsageRow,
    ProductionOrderProductLine,
    ProductionOrderProductLineUsageRow,
    ProductionOrderSetData,
} from '@/types/production-orders';

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

/**
 * Agregar la primera fuente de un producto que la fórmula no lleva crea su línea (y quitar la última la borra): aquí se
 * rehace la lista de productos del formulario con las líneas de ahora, sin perder lo escrito.
 */
export function useProductLineUsageSync({
    lines,
    currentRows,
    setData,
}: {
    lines: ProductionOrderProductLine[];
    currentRows: ProductionOrderProductLineUsageRow[];
    setData: ProductionOrderSetData;
}) {
    const currentRowsRef = useRef(currentRows);
    const lineIds = useMemo(
        () => lines.map((line) => line.id).join(','),
        [lines],
    );

    useEffect(() => {
        currentRowsRef.current = currentRows;
    }, [currentRows]);

    useEffect(() => {
        const current = currentRowsRef.current;

        if (current.map((row) => row.id).join(',') === lineIds) {
            return;
        }

        setData(
            'product_lines',
            lines.map((line) =>
                mapProductLineToUsageRow(
                    line,
                    current.find((row) => row.id === line.id),
                ),
            ),
        );
    }, [lineIds, lines, setData]);
}
