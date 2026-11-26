/**
 * La «hora de pared» de la planta: un instante (`2026-11-15T02:00:00Z`) visto con el reloj de la planta
 * (`America/Bogota`), no con el del navegador. Un celular con la zona mal puesta mostraría las horas corridas.
 *
 * La zona sale de la prop compartida `plantTimezone`; `app.tsx` la fija al cargar cada página. Sin ella (pruebas, una
 * página sin la prop) se usa `America/Bogota`.
 */

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

/**
 * El instante como fecha local con los campos del reloj de la planta, para dárselo a `format` de date-fns, que formatea
 * con la zona del navegador. Solo sirve para mostrar: no es el mismo instante.
 */
export function toPlantWallClockDate(instant: Date): Date {
    const clock = toPlantWallClock(instant);

    return new Date(
        clock.year,
        clock.month - 1,
        clock.day,
        clock.hour,
        clock.minute,
        clock.second,
    );
}

/**
 * Hoy en la planta según el reloj de este equipo, como fecha local a medianoche. Solo es el respaldo de `plantToday`
 * (la prop compartida, que manda): sin ella, por lo menos el día sale con la zona de planta y no con la del navegador.
 */
export function plantClockToday(): Date {
    const clock = toPlantWallClock(new Date());

    return new Date(clock.year, clock.month - 1, clock.day);
}
