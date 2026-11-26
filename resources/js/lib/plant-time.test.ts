import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    formatRelativeTime,
    formatSafeDate,
} from '@/components/formatted-date';
import { toPlantDateTimeInput } from '@/lib/date-time-helpers';
import {
    currentPlantToday,
    getPlantTimezone,
    hasTimeZone,
    plantClockToday,
    plantWallClockOf,
    setPlantTimezone,
    toPlantWallClock,
} from '@/lib/plant-time';

/*
 * Las horas se muestran con el reloj de la planta (`America/Bogota`, UTC-5), no con el del navegador. El CI corre en
 * UTC: si la conversión no se aplicara, estas pruebas darían el día siguiente.
 */

const originalTimezone = process.env.TZ;

afterEach(() => {
    setPlantTimezone('America/Bogota');
    vi.useRealTimers();

    // Asignar `undefined` dejaría el texto «undefined» (que Node toma como UTC), no la zona del equipo.
    if (originalTimezone === undefined) {
        delete process.env.TZ;
    } else {
        process.env.TZ = originalTimezone;
    }
});

/** Simula un navegador en otra zona: Node aplica `process.env.TZ` a los `Date` que se crean después. */
function browserTimezone(timeZone: string): void {
    process.env.TZ = timeZone;
}

const ymd = (date: Date) => [
    date.getFullYear(),
    date.getMonth() + 1,
    date.getDate(),
];

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

describe('toPlantDateTimeInput: campos de hora de la OP', () => {
    it('muestra la hora del servidor con el reloj de la planta', () => {
        expect(toPlantDateTimeInput('2026-11-15T02:00:00Z')).toBe(
            '2026-11-14T21:00',
        );
        expect(toPlantDateTimeInput('2026-11-15T02:00:00.000000Z')).toBe(
            '2026-11-14T21:00',
        );
    });

    it('devuelve tal cual lo que se acaba de digitar (sin zona), sin convertirlo dos veces', () => {
        expect(toPlantDateTimeInput('2026-11-14T21:00')).toBe(
            '2026-11-14T21:00',
        );
        // Con espacio y segundos: sale en el formato del campo, que exige la `T`.
        expect(toPlantDateTimeInput('2026-11-14 21:00:00')).toBe(
            '2026-11-14T21:00',
        );
    });

    it('deja vacío lo que no es una fecha', () => {
        expect(toPlantDateTimeInput(null)).toBe('');
        expect(toPlantDateTimeInput('')).toBe('');
        expect(toPlantDateTimeInput('no es fecha Z')).toBe('');
    });
});

describe('plantWallClockOf: los campos de la planta para un texto del servidor', () => {
    it('convierte un instante con zona y lee tal cual un día o una hora sin zona', () => {
        expect(plantWallClockOf('2026-11-15T02:00:00.000000Z')).toMatchObject({
            day: 14,
            hour: 21,
        });
        expect(plantWallClockOf('2026-11-14 21:00:30')).toEqual({
            year: 2026,
            month: 11,
            day: 14,
            hour: 21,
            minute: 0,
            second: 30,
        });
        expect(plantWallClockOf('2026-11-14T21:00')).toMatchObject({
            day: 14,
            hour: 21,
        });
        expect(plantWallClockOf('2026-11-14')).toMatchObject({
            day: 14,
            hour: 0,
        });
        expect(plantWallClockOf('mañana')).toBeNull();
    });
});

describe('un navegador en una zona con horario de verano', () => {
    it('no corre una hora que no existe en su zona (02:30 del 29 de marzo en Madrid)', () => {
        browserTimezone('Europe/Madrid');

        // 07:30 UTC = 02:30 en Bogotá; en Madrid de 02:00 a 03:00 no existe ese día.
        expect(formatSafeDate('2026-03-29T07:30:00Z', 'datetime')).toBe(
            '29 mar 2026, 02:30',
        );
        expect(formatSafeDate('2026-03-29 02:30:00', 'datetime')).toBe(
            '29 mar 2026, 02:30',
        );
        expect(toPlantDateTimeInput('2026-03-29T07:30:00Z')).toBe(
            '2026-03-29T02:30',
        );
    });
});

describe('currentPlantToday: hoy en la planta al momento del clic', () => {
    it('pasa al día siguiente después de la medianoche aunque la página se cargó la noche anterior', () => {
        vi.useFakeTimers();
        // 00:10 del 26 en Bogotá; la página se cargó el 25 a las 23:50.
        vi.setSystemTime(new Date('2026-11-26T05:10:00Z'));

        expect(ymd(currentPlantToday('2026-11-25'))).toEqual([2026, 11, 26]);
    });

    it('no deja que un reloj del equipo atrasado gane al servidor', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-20T15:00:00Z'));

        expect(ymd(currentPlantToday('2026-11-26'))).toEqual([2026, 11, 26]);
    });

    it('usa el reloj de la planta si falta plantToday', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-15T02:00:00Z'));

        expect(ymd(currentPlantToday(undefined))).toEqual([2026, 11, 14]);
    });
});
