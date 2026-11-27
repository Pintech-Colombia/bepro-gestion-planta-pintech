import { describe, expect, it } from 'vitest';

import { normalizeProductionOrderFormData } from '@/lib/production-order-form';
import type { ProductionOrderFormData } from '@/types/production-orders';

const baseData = {
    viscosity_ku: '',
    grinding_hg: '',
    quality_solids: '',
    spillage_quantity: '',
    density_kg_per_gallon: '3,8',
    remnant_quantity_kg: '',
    ingredients: [],
    packaging: [],
    product_lines: [],
    batch_consumptions: [],
} as unknown as ProductionOrderFormData;

describe('normalizeProductionOrderFormData', () => {
    it('envía los kilos de los productos con punto decimal', () => {
        const payload = normalizeProductionOrderFormData({
            ...baseData,
            product_lines: [{ id: 7, actual_kg: '2,5' }],
        });

        expect(payload.product_lines).toEqual([{ id: 7, actual_kg: '2.5' }]);
        expect(payload.density_kg_per_gallon).toBe('3.8');
    });

    it('solo envía lo que quedó en el cuñete cuando la respuesta es sí', () => {
        const payload = normalizeProductionOrderFormData({
            ...baseData,
            batch_consumptions: [
                { id: 1, has_leftover: true, leftover_kg: '1,2' },
                { id: 2, has_leftover: false, leftover_kg: '4' },
            ],
        });

        expect(payload.batch_consumptions).toEqual([
            { id: 1, has_leftover: true, leftover_kg: '1.2' },
            { id: 2, has_leftover: false, leftover_kg: null },
        ]);
    });

    it('deja vacíos los envases y etiquetas sin escribir (tantos como lo envasado)', () => {
        const payload = normalizeProductionOrderFormData({
            ...baseData,
            packaging: [
                {
                    id: 3,
                    actual_units: '20',
                    new_containers_used: '',
                    label_raw_material_id: null,
                    labels_used: '',
                },
            ],
        } as unknown as ProductionOrderFormData);

        expect(payload.packaging).toEqual([
            {
                id: 3,
                actual_units: '20',
                new_containers_used: null,
                label_raw_material_id: null,
                labels_used: null,
            },
        ]);
    });
});
