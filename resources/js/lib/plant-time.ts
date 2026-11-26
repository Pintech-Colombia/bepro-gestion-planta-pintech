/**
 * La «hora de pared» de la planta: un instante (`2026-11-15T02:00:00Z`) visto con el reloj de la planta
 * (`America/Bogota`), no con el del navegador. Un celular con la zona mal puesta mostraría las horas corridas.
 *
 * La zona sale de la prop compartida `plantTimezone`; `app.tsx` la fija una vez, antes del primer render. Sin ella
 * (pruebas, una página sin la prop) se usa `America/Bogota`.
 */

import { latestPlantDate, parsePlantDate, toPlantDate } from '@/lib/plant-date';

const DEFAULT_PLANT_TIMEZONE = 'America/Bogota';

/** Un texto que termina en `Z` o en un desfase (`-05:00`, `+0000`) es un instante exacto; sin zona, es hora de planta. */
const HAS_ZONE = /(Z|[+-]\d{2}:?\d{2})$/i;

let plantTimezone = DEFAULT_PLANT_TIMEZONE;
let formatter: Intl.DateTimeFormat | null = null;

function buildFormatter(timeZone: string): Intl.DateTimeFormat {
    return new Intl.DateTimeFormat('en-US', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

/** Fija la zona de planta. Una zona vacía o que el navegador no reconoce se ignora: se queda la anterior. */
export function setPlantTimezone(timeZone: string | null | undefined): void {
    if (!timeZone || timeZone === plantTimezone) {
        return;
    }

    try {
        formatter = buildFormatter(timeZone);
        plantTimezone = timeZone;
    } catch {
        // RangeError: zona inválida.
    }
}

export function getPlantTimezone(): string {
    return plantTimezone;
}

export function hasTimeZone(value: string): boolean {
    return HAS_ZONE.test(value.trim());
}

export type PlantWallClock = {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
    second: number;
};

/** Los campos del reloj de la planta en ese instante (mes de 1 a 12). */
export function toPlantWallClock(instant: Date): PlantWallClock {
    formatter ??= buildFormatter(plantTimezone);

    const parts = Object.fromEntries(
        formatter
            .formatToParts(instant)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, Number(part.value)]),
    );

    return {
        year: parts.year,
        month: parts.month,
        day: parts.day,
        hour: parts.hour,
        minute: parts.minute,
        second: parts.second,
    };
}

/** `YYYY-MM-DD`, con hora opcional (`T` o espacio) y segundos opcionales; lo que sigue (fracciones) se ignora. */
const WALL_CLOCK_TEXT =
    /^(\d{4})-(\d{2})-(\d{2})(?:[T\s]+(\d{2}):(\d{2})(?::(\d{2}))?)?/;

/**
 * Los campos del reloj de la planta para un texto del servidor: un instante con zona se convierte; un día o una hora
 * sin zona ya son de planta y se leen tal cual, sin pasar por la zona del navegador. `null` si no es una fecha.
 *
 * Se trabaja con los campos y no con un `Date` local: en un navegador con horario de verano, una hora que allí no existe
 * (las 02:30 del día del cambio) se correría una hora.
 */
export function plantWallClockOf(value: string): PlantWallClock | null {
    const trimmed = value.trim();

    if (hasTimeZone(trimmed)) {
        const instant = new Date(trimmed);

        return isNaN(instant.getTime()) ? null : toPlantWallClock(instant);
    }

    const match = WALL_CLOCK_TEXT.exec(trimmed);

    if (!match) {
        return null;
    }

    return {
        year: Number(match[1]),
        month: Number(match[2]),
        day: Number(match[3]),
        hour: Number(match[4] ?? 0),
        minute: Number(match[5] ?? 0),
        second: Number(match[6] ?? 0),
    };
}

/**
 * Hoy en la planta según el reloj de este equipo, como fecha local a medianoche. Solo es el respaldo de `plantToday`
 * (la prop compartida, que manda): sin ella, por lo menos el día sale con la zona de planta y no con la del navegador.
 */
export function plantClockToday(): Date {
    const clock = toPlantWallClock(new Date());

    return new Date(clock.year, clock.month - 1, clock.day);
}

/**
 * Hoy en la planta en este momento, como fecha local a medianoche: el más tardío entre `plantToday` (la prop del
 * servidor, del momento en que cargó la página) y el reloj del equipo en la zona de planta. Una página abierta antes de
 * la medianoche ya da el día siguiente sin recargar, y un equipo con el reloj atrasado no gana al servidor.
 */
export function currentPlantToday(plantToday: string | null | undefined): Date {
    const clockToday = plantClockToday();

    return (
        parsePlantDate(latestPlantDate(plantToday, toPlantDate(clockToday))) ??
        clockToday
    );
}
