import { describe, expect, it } from 'vitest';

import { variantPresentationLabel } from './variant-label';

describe('variantPresentationLabel', () => {
    it('muestra la presentación con sus galones, sin el producto', () => {
        expect(
            variantPresentationLabel({
                presentation_label: 'Galón',
                presentation_value: 1,
            }),
        ).toBe('Galón (1 gal)');
    });

    it('formatea el contenido con coma decimal', () => {
        expect(
            variantPresentationLabel({
                presentation_label: 'Cuarto',
                presentation_value: '0.2500',
            }),
        ).toBe('Cuarto (0,25 gal)');
    });

    it('sin contenido, solo la presentación', () => {
        expect(
            variantPresentationLabel({
                presentation_label: 'Unidad',
                presentation_value: null,
            }),
        ).toBe('Unidad');
    });
});
