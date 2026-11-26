import { describe, expect, it } from 'vitest';

import { buildUrlTabHref } from '@/lib/url-tab';

describe('buildUrlTabHref', () => {
    it('agrega el parámetro al salir de la pestaña por defecto y conserva otros parámetros', () => {
        expect(
            buildUrlTabHref(
                '/raw-materials/5?return_to=%2Fraw-materials%3Fsearch%3DMP',
                {
                    param: 'batches',
                    value: 'exhausted',
                    defaultValue: 'active',
                    clearParams: ['batches_page'],
                },
            ),
        ).toBe(
            '/raw-materials/5?return_to=%2Fraw-materials%3Fsearch%3DMP&batches=exhausted',
        );
    });

    it('omite el parámetro y limpia clearParams al volver a la pestaña por defecto', () => {
        expect(
            buildUrlTabHref(
                '/raw-materials/5?batches=exhausted&batches_page=2&return_to=%2Fraw-materials',
                {
                    param: 'batches',
                    value: 'active',
                    defaultValue: 'active',
                    clearParams: ['batches_page'],
                },
            ),
        ).toBe('/raw-materials/5?return_to=%2Fraw-materials');
    });

    it('devuelve solo el pathname cuando no quedan parámetros', () => {
        expect(
            buildUrlTabHref(
                '/raw-materials/5?batches=exhausted&batches_page=3',
                {
                    param: 'batches',
                    value: 'active',
                    defaultValue: 'active',
                    clearParams: ['batches_page'],
                },
            ),
        ).toBe('/raw-materials/5');
    });
});
