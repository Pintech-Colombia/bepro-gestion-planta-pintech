import { describe, expect, it } from 'vitest';

import { packagingVariantLabel } from './variant-label';

describe('packagingVariantLabel', () => {
    it('muestra la presentación con sus galones, sin el producto', () => {
        expect(
            packagingVariantLabel({
                presentation_label: 'Galón',
                presentation_value: 1,
            }),
        ).toBe('Galón (1 gal)');
    });

    it('formatea el contenido con coma decimal', () => {
        expect(
            packagingVariantLabel({
                presentation_label: 'Cuarto',
                presentation_value: '0.2500',
            }),
        ).toBe('Cuarto (0,25 gal)');
    });

    it('sin contenido, solo la presentación', () => {
        expect(
            packagingVariantLabel({
                presentation_label: 'Unidad',
                presentation_value: null,
            }),
        ).toBe('Unidad');
    });
});
