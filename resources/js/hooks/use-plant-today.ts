import { usePage } from '@inertiajs/react';

import { toPlantDate } from '@/lib/plant-date';
import { currentPlantToday } from '@/lib/plant-time';

/**
 * Hoy en la planta (`YYYY-MM-DD`) en el momento de dibujar: el más tardío entre `plantToday` (del servidor, de cuando
 * cargó la página) y el reloj del equipo en la zona de planta. Es el único «hoy» de los selectores de fecha: una página
 * abierta antes de la medianoche ya da el día siguiente al abrir el calendario, igual que el servidor al validar.
 */
export function usePlantToday(): string {
    const { plantToday } = usePage().props;

    return toPlantDate(currentPlantToday(plantToday));
}
