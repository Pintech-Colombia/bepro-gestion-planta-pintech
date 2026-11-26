import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    formatRelativeTime,
    formatSafeDate,
} from '@/components/formatted-date';
import { toLocalDateTimeInput } from '@/lib/date-time-helpers';
import {
    getPlantTimezone,
    hasTimeZone,
    plantClockToday,
    setPlantTimezone,
    toPlantWallClock,
} from '@/lib/plant-time';

/*
 * Las horas se muestran con el reloj de la planta (`America/Bogota`, UTC-5), no con el del navegador. El CI corre en
 * UTC: si la conversión no se aplicara, estas pruebas darían el día siguiente.
 */

afterEach(() => {
    setPlantTimezone('America/Bogota');
    vi.useRealTimers();
});

describe('reloj de la planta', () => {
    it('usa America/Bogota por defecto', () => {
        expect(getPlantTimezone()).toBe('America/Bogota');
    });

    it('lee un instante con el reloj de la planta, con la hora de 0 a 23', () => {
        expect(toPlantWallClock(new Date('2026-11-15T02:00:00Z'))).toEqual({
            year: 2026,
            month: 11,
            day: 14,
            hour: 21,
            minute: 0,
            second: 0,
        });
        // Medianoche en la planta: 00, no 24.
        expect(toPlantWallClock(new Date('2026-11-15T05:00:00Z')).hour).toBe(0);
    });

    it('cambia de zona con la prop compartida e ignora una zona vacía o inválida', () => {
        setPlantTimezone('Asia/Tokyo');
        expect(toPlantWallClock(new Date('2026-11-15T02:00:00Z')).hour).toBe(
            11,
        );

        setPlantTimezone(undefined);
        setPlantTimezone('Zona/Inexistente');
        expect(getPlantTimezone()).toBe('Asia/Tokyo');
    });

    it('distingue un instante con zona de una hora sin zona', () => {
        expect(hasTimeZone('2026-11-15T02:00:00.000000Z')).toBe(true);
        expect(hasTimeZone('2026-11-14T21:00:00-05:00')).toBe(true);
        expect(hasTimeZone('2026-11-14T21:00')).toBe(false);
        expect(hasTimeZone('2026-11-14 21:00:00')).toBe(false);
        expect(hasTimeZone('2026-11-14')).toBe(false);
    });

    it('da hoy en la planta con el reloj del equipo, como respaldo de plantToday', () => {
        vi.useFakeTimers();
        // El 14 a las 9 p. m. en Bogotá; en UTC ya es el 15.
        vi.setSystemTime(new Date('2026-11-15T02:00:00Z'));

        const today = plantClockToday();

        expect([
            today.getFullYear(),
            today.getMonth(),
            today.getDate(),
        ]).toEqual([2026, 10, 14]);
    });
});

describe('formatSafeDate en hora de planta', () => {
    it('muestra un instante del servidor con el reloj de la planta', () => {
        expect(formatSafeDate('2026-11-15T02:00:00.000000Z', 'datetime')).toBe(
            '14 nov 2026, 21:00',
        );
        expect(formatSafeDate('2026-11-15T02:00:00.000000Z', 'short')).toBe(
            '14 nov 2026',
        );
    });

    it('deja igual los días y las horas sin zona, que ya son de planta', () => {
        expect(formatSafeDate('2026-11-14')).toBe('14 nov 2026');
        expect(formatSafeDate('2026-11-14 21:00:00', 'datetime')).toBe(
            '14 nov 2026, 21:00',
        );
    });

    it('no cambia un tiempo relativo: una diferencia no depende de la zona', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-15T03:00:00Z'));

        expect(formatRelativeTime('2026-11-15T02:00:00.000000Z')).toBe(
            'hace alrededor de 1 hora',
        );
    });
});

describe('toLocalDateTimeInput: campos de hora de la OP', () => {
    it('muestra la hora del servidor con el reloj de la planta', () => {
        expect(toLocalDateTimeInput('2026-11-15T02:00:00Z')).toBe(
            '2026-11-14T21:00',
        );
        expect(toLocalDateTimeInput('2026-11-15T02:00:00.000000Z')).toBe(
            '2026-11-14T21:00',
        );
    });

    it('devuelve tal cual lo que se acaba de digitar (sin zona), sin convertirlo dos veces', () => {
        expect(toLocalDateTimeInput('2026-11-14T21:00')).toBe(
            '2026-11-14T21:00',
        );
    });

    it('deja vacío lo que no es una fecha', () => {
        expect(toLocalDateTimeInput(null)).toBe('');
        expect(toLocalDateTimeInput('')).toBe('');
        expect(toLocalDateTimeInput('no es fecha Z')).toBe('');
    });
});
