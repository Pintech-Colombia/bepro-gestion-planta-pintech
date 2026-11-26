import { plantWallClockOf } from '@/lib/plant-time';

/**
 * El valor de un campo `datetime-local` (`YYYY-MM-DDTHH:mm`) en hora de planta, no del navegador.
 *
 * - Un instante con zona (lo que manda el servidor, `2026-11-15T02:00:00Z`) se muestra con el reloj de la planta:
 *   `2026-11-14T21:00`.
 * - Un valor sin zona (lo que se acaba de digitar) ya es hora de planta: sale igual, en el formato del campo. Se envía
 *   así, sin convertir: el servidor lee una hora sin zona como hora de planta.
 */
export function toPlantDateTimeInput(value: string | null | undefined): string {
    const clock = value ? plantWallClockOf(value) : null;

    if (!clock) {
        return '';
    }

    const pad = (n: number) => String(n).padStart(2, '0');

    return `${clock.year}-${pad(clock.month)}-${pad(clock.day)}T${pad(clock.hour)}:${pad(clock.minute)}`;
}
