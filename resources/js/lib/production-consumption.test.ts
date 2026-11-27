import { describe, expect, it } from 'vitest';

import { orderByStep } from '@/lib/production-consumption';

describe('orderByStep', () => {
    it('ordena por paso, conserva el orden recibido dentro del paso y deja sin paso al final', () => {
        const rows = [
            { name: 'PT agregado', step: null },
            { name: 'MP paso 2', step: 2 },
            { name: 'MP paso 1', step: 1 },
            { name: 'PT paso 1', step: 1 },
        ];

        expect(
            orderByStep(rows, (row) => row.step).map((row) => row.name),
        ).toEqual(['MP paso 1', 'PT paso 1', 'MP paso 2', 'PT agregado']);
    });
});
