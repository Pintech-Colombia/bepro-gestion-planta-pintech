import { describe, expect, it } from 'vitest';

import {
    normalizeMarginKey,
    resolveMarginPreviewState,
} from '@/lib/price-list-margin';
import type { MarginPreviewResponse } from '@/lib/price-list-margin';

describe('normalizeMarginKey', () => {
    it('equates equivalent margins regardless of decimal separator or trailing zeros', () => {
        expect(normalizeMarginKey('25,00')).toBe('25');
        expect(normalizeMarginKey('25.00')).toBe('25');
        expect(normalizeMarginKey('25')).toBe('25');
        expect(normalizeMarginKey('05,50')).toBe('5.5');
        expect(normalizeMarginKey('0.00')).toBe('0');
        expect(normalizeMarginKey('  ')).toBe('');
    });

    it('preserves non-numeric text so validation can flag it', () => {
        expect(normalizeMarginKey('abc')).toBe('abc');
        expect(normalizeMarginKey('12.3.4')).toBe('12.3.4');
    });
});

describe('resolveMarginPreviewState', () => {
    const samplePreview: MarginPreviewResponse = {
        product_sales_price: 131,
        variants: [{ id: 10, sales_price: 657 }],
    };

    it('ignores stale preview results and errors when not dirty', () => {
        const state = resolveMarginPreviewState({
            draft: null,
            savedMargin: '25.00',
            previewResult: { value: samplePreview, context: '30' },
            previewErrors: {
                value: { sales_margin: 'Máximo 99.99' },
                context: '100',
            },
            previewStale: 'failed',
        });

        expect(state).toEqual({
            isDirty: false,
            variantPrices: null,
            validationError: null,
            failed: false,
            isCurrent: false,
        });
    });

    it('discards stale preview errors from a previous canceled draft', () => {
        const state = resolveMarginPreviewState({
            draft: '20',
            savedMargin: '25.00',
            previewResult: null,
            previewErrors: {
                value: { sales_margin: 'El margen no debe superar 99.99.' },
                context: '100',
            },
            previewStale: 'invalid',
        });

        expect(state.isDirty).toBe(true);
        expect(state.validationError).toBeNull();
        expect(state.isCurrent).toBe(false);
    });

    it('discards stale preview prices from a previous canceled draft', () => {
        const state = resolveMarginPreviewState({
            draft: '1',
            savedMargin: '25.00',
            previewResult: { value: samplePreview, context: '50' },
            previewErrors: null,
            previewStale: null,
        });

        expect(state.isDirty).toBe(true);
        expect(state.variantPrices).toBeNull();
        expect(state.isCurrent).toBe(false);
    });

    it('exposes variant prices only when context matches current draft and preview is fresh', () => {
        const state = resolveMarginPreviewState({
            draft: '30',
            savedMargin: '25.00',
            previewResult: { value: samplePreview, context: '30' },
            previewErrors: null,
            previewStale: null,
        });

        expect(state.isDirty).toBe(true);
        expect(state.isCurrent).toBe(true);
        expect(state.failed).toBe(false);
        expect(state.validationError).toBeNull();
        expect(state.variantPrices?.get(10)).toBe(657);
    });

    it('surfaces validation error when previewErrors context matches current draft', () => {
        const state = resolveMarginPreviewState({
            draft: '100',
            savedMargin: '25.00',
            previewResult: { value: samplePreview, context: '30' },
            previewErrors: {
                value: { sales_margin: 'Debe ser menor o igual a 99.99.' },
                context: '100',
            },
            previewStale: 'invalid',
        });

        expect(state.validationError).toBe('Debe ser menor o igual a 99.99.');
        expect(state.variantPrices).toBeNull();
        expect(state.isCurrent).toBe(false);
    });

    it('flags network/rate-limit failure without showing outdated prices as current', () => {
        const state = resolveMarginPreviewState({
            draft: '30',
            savedMargin: '25.00',
            previewResult: { value: samplePreview, context: '30' },
            previewErrors: null,
            previewStale: 'failed',
        });

        expect(state.failed).toBe(true);
        expect(state.variantPrices).toBeNull();
        expect(state.isCurrent).toBe(false);
    });
});
