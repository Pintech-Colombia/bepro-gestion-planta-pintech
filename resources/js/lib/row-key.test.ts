import { describe, expect, it } from 'vitest';

import { newRowKey } from '@/lib/row-key';

describe('newRowKey', () => {
    it('devuelve claves únicas en cada llamada', () => {
        const keys = [newRowKey(), newRowKey(), newRowKey()];

        expect(new Set(keys).size).toBe(3);
        keys.forEach((key) => {
            expect(key).toMatch(/^row-\d+$/);
        });
    });
});
