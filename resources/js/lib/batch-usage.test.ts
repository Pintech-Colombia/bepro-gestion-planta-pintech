import { describe, expect, it } from 'vitest';

import {
    batchOverfillWarning,
    batchUnitsSuggestion,
    batchUsageSummary,
} from '@/lib/batch-usage';

describe('batchUsageSummary', () => {
    it('dice las unidades enteras y los kilos de la siguiente (la hoja de planta)', () => {
        expect(batchUsageSummary('462,3', '23.4')).toBe(
            '462,3 kg ≈ 19 unidades + 17,7 kg de la 20',
        );
    });

    it('cuenta exacto un múltiplo de la unidad, sin errores de coma flotante', () => {
        // 468 ÷ 23,4 en coma flotante da 19,999…
        expect(batchUsageSummary('468', '23.4')).toBe('468 kg = 20 unidades');
        expect(batchUsageSummary('205', '20.5')).toBe('205 kg = 10 unidades');
        expect(batchUsageSummary('20,5', '20.5')).toBe('20,5 kg = 1 unidad');
    });

    it('con menos de una unidad no habla de unidades enteras', () => {
        expect(batchUsageSummary('10', '23.4')).toBe(
            '10 kg: menos de una unidad',
        );
    });

    it('sin kilos usados o sin kilos por unidad no dice nada', () => {
        expect(batchUsageSummary('', '23.4')).toBeNull();
        expect(batchUsageSummary('0', '23.4')).toBeNull();
        expect(batchUsageSummary('462,3', null)).toBeNull();
        expect(batchUsageSummary('abc', '23.4')).toBeNull();
    });
});

describe('batchUnitsSuggestion', () => {
    it('sugiere bajar las unidades si con menos alcanza', () => {
        expect(batchUnitsSuggestion('440', '', '23.4', 20)).toBe(
            'Con 19 unidades alcanza: si la otra no se abrió, baja las unidades del lote y vuelve a bodega.',
        );
        expect(batchUnitsSuggestion('400', '', '23.4', 20)).toBe(
            'Con 18 unidades alcanza: si las otras 2 no se abrieron, baja las unidades del lote y vuelven a bodega.',
        );
    });

    it('cuenta lo que quedó en el envase abierto como parte de esa unidad', () => {
        // 462,3 + 5,7 = 468 = 20 cuñetes justos.
        expect(batchUnitsSuggestion('462,3', '5,7', '23.4', 20)).toBeNull();
        // Sin lo que quedó, 462,3 también pide 20.
        expect(batchUnitsSuggestion('462,3', '', '23.4', 20)).toBeNull();
    });

    it('no sugiere nada con las unidades justas, de más kilos o sin datos', () => {
        expect(batchUnitsSuggestion('205', '', '20.5', 10)).toBeNull();
        expect(batchUnitsSuggestion('500', '', '23.4', 20)).toBeNull();
        expect(batchUnitsSuggestion('', '', '23.4', 20)).toBeNull();
        expect(batchUnitsSuggestion('100', '', null, 20)).toBeNull();
    });
});

describe('batchOverfillWarning', () => {
    it('avisa cuando los kilos usados más lo que quedó pasan de las unidades', () => {
        expect(batchOverfillWarning('465', '5,7', '23.4', 20)).toBe(
            'Los kilos usados más lo que quedó (470,7 kg) pasan de lo que traen 20 unidades (468 kg). Revisa los kilos o las unidades; si están bien, puedes seguir.',
        );
        expect(batchOverfillWarning('210', '', '20.5', 10)).toBe(
            'Los kilos usados (210 kg) pasan de lo que traen 10 unidades (205 kg). Revisa los kilos o las unidades; si están bien, puedes seguir.',
        );
    });

    it('no avisa si cabe justo o faltan datos', () => {
        expect(batchOverfillWarning('462,3', '5,7', '23.4', 20)).toBeNull();
        expect(batchOverfillWarning('205', '', '20.5', 10)).toBeNull();
        expect(batchOverfillWarning('', '5', '23.4', 20)).toBeNull();
        expect(batchOverfillWarning('300', '', null, 10)).toBeNull();
    });
});
