import { describe, expect, it } from 'vitest';

import {
    lineHasSecondaryError,
    quotationLineSummary,
} from '@/lib/quotation-line-details';

describe('quotationLineSummary: lo que dice «Más detalles» cerrado', () => {
    it('junta tipo, color y ajuste con « · »', () => {
        expect(
            quotationLineSummary({
                typeLabel: 'Imprimante',
                color: 'RAL 7035',
                adjustment: '-5',
            }),
        ).toBe('Imprimante · RAL 7035 · Ajuste -5%');
    });

    it('deja fuera lo vacío y un ajuste de cero', () => {
        expect(
            quotationLineSummary({
                typeLabel: '',
                color: '  ',
                adjustment: '0',
            }),
        ).toBe('');
        expect(
            quotationLineSummary({
                typeLabel: null,
                color: 'Blanco',
                adjustment: '',
            }),
        ).toBe('Blanco');
    });

    it('muestra un recargo y los decimales del ajuste', () => {
        expect(quotationLineSummary({ adjustment: '7.5' })).toBe('Ajuste 7,5%');
        expect(quotationLineSummary({ adjustment: 12 })).toBe('Ajuste 12%');
    });
});

describe('lineHasSecondaryError: si «Más detalles» se abre por un error', () => {
    it('se abre con error en cualquier campo secundario', () => {
        expect(
            lineHasSecondaryError((field) =>
                field === 'price_adjustment_pct' ? 'No válido.' : undefined,
            ),
        ).toBe(true);
        expect(
            lineHasSecondaryError((field) =>
                field === 'color' ? 'Muy largo.' : undefined,
            ),
        ).toBe(true);
    });

    it('sin errores secundarios queda como lo dejó el usuario', () => {
        expect(lineHasSecondaryError(() => undefined)).toBe(false);
        expect(lineHasSecondaryError(() => '')).toBe(false);
    });
});
