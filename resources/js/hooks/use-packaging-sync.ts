import { useEffect, useMemo, useRef } from 'react';

import { mapPackagingPlanToFormRow } from '@/lib/production-order-form';
import type {
    ProductionOrderPackagingFormRow,
    ProductionOrderPackagingPlan,
    ProductionOrderSetData,
} from '@/types/production-orders';

type UsePackagingSyncProps = {
    packagingPlans: ProductionOrderPackagingPlan[];
    currentPackaging: ProductionOrderPackagingFormRow[];
    setData: ProductionOrderSetData;
};

export function usePackagingSync({
    packagingPlans,
    currentPackaging,
    setData,
}: UsePackagingSyncProps) {
    const currentPackagingRef = useRef(currentPackaging);
    const packagingPlanIds = useMemo(
        () => packagingPlans.map((pack) => pack.id).join(','),
        [packagingPlans],
    );

    useEffect(() => {
        currentPackagingRef.current = currentPackaging;
    }, [currentPackaging]);

    useEffect(() => {
        const nextPackaging = packagingPlans.map((pack) =>
            mapPackagingPlanToFormRow(
                pack,
                currentPackagingRef.current.find((item) => item.id === pack.id),
            ),
        );

        const isEquivalent =
            currentPackagingRef.current.length === nextPackaging.length &&
            nextPackaging.every((nextItem) => {
                const currentItem = currentPackagingRef.current.find(
                    (item) => item.id === nextItem.id,
                );

                if (!currentItem) {
                    return false;
                }

                return (
                    currentItem.presentation === nextItem.presentation &&
                    currentItem.presentation_value ===
                        nextItem.presentation_value &&
                    currentItem.planned_units === nextItem.planned_units &&
                    currentItem.actual_units === nextItem.actual_units &&
                    currentItem.package_code === nextItem.package_code &&
                    currentItem.new_containers_used ===
                        nextItem.new_containers_used &&
                    currentItem.label_raw_material_id ===
                        nextItem.label_raw_material_id &&
                    currentItem.labels_used === nextItem.labels_used &&
                    currentItem.saved_label_raw_material_id ===
                        nextItem.saved_label_raw_material_id
                );
            });

        if (!isEquivalent) {
            setData('packaging', nextPackaging);
        }
    }, [packagingPlanIds, packagingPlans, setData]);
}
