import { format, formatDistanceToNow } from 'date-fns';
import { es } from 'date-fns/locale';

import { EmptyCell } from '@/components/empty-cell';
import { plantWallClockOf } from '@/lib/plant-time';
import { cn } from '@/lib/utils';

export interface FormattedDateProps {
    value: string | null | undefined;
    format?: 'short' | 'long' | 'datetime' | 'date';
    /** Texto sin fecha, atenuado (por defecto pinta <EmptyCell />). Ej.: «Sin estimar» */
    emptyValue?: string;
    className?: string;
}

/** El día de cada formato; `datetime` le agrega `, HH:mm` (15 ene 2024, 10:30). */
const dayPatterns = {
    short: 'dd MMM yyyy', // 15 ene 2024
    long: 'dd MMMM yyyy', // 15 enero 2024
    datetime: 'dd MMM yyyy', // 15 ene 2024, 10:30
    date: 'dd/MM/yyyy', // 15/01/2024
};

/**
 * Parsea de manera segura fechas ISO o cadenas YYYY-MM-DD sin desfase de zona horaria.
 */
export function parseSafeDate(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const trimmed = value.trim();

    // 1. Fecha de negocio pura: YYYY-MM-DD
    const dateOnlyMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (dateOnlyMatch) {
        const year = Number(dateOnlyMatch[1]);
        const month = Number(dateOnlyMatch[2]) - 1; // 0-indexed
        const day = Number(dateOnlyMatch[3]);

        return new Date(year, month, day);
    }

    // 2. Fecha con espacio: YYYY-MM-DD HH:mm:ss
    const dateTimeSpaceMatch = trimmed.match(
        /^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/,
    );

    if (dateTimeSpaceMatch) {
        const year = Number(dateTimeSpaceMatch[1]);
        const month = Number(dateTimeSpaceMatch[2]) - 1;
        const day = Number(dateTimeSpaceMatch[3]);
        const hours = Number(dateTimeSpaceMatch[4]);
        const minutes = Number(dateTimeSpaceMatch[5]);
        const seconds = Number(dateTimeSpaceMatch[6] ?? '0');

        return new Date(year, month, day, hours, minutes, seconds);
    }

    // 3. Timestamp ISO 8601 (con T o Z). Es el instante real: `formatSafeDate` lo muestra en hora de planta.
    const date = new Date(trimmed);

    if (isNaN(date.getTime())) {
        return null;
    }

    return date;
}

export function formatSafeDate(
    value: string | null | undefined,
    formatType: FormattedDateProps['format'] = 'short',
    emptyValue = '—',
): string {
    if (!value) {
        return emptyValue;
    }

    try {
        const date = parseSafeDate(value);

        if (!date) {
            return emptyValue;
        }

        // Un instante con zona (caso 3) se muestra con el reloj de la planta, no con el del navegador; los casos 1 y 2
        // (sin zona) ya son día u hora de planta. Un texto en otro formato queda como lo leyó el navegador.
        const clock = plantWallClockOf(value) ?? {
            year: date.getFullYear(),
            month: date.getMonth() + 1,
            day: date.getDate(),
            hour: date.getHours(),
            minute: date.getMinutes(),
        };
        // El día se formatea a mediodía: ningún cambio de horario de verano cae a esa hora, así que el día no se corre.
        const dayText = format(
            new Date(clock.year, clock.month - 1, clock.day, 12),
            dayPatterns[formatType],
            { locale: es },
        );

        if (formatType !== 'datetime') {
            return dayText;
        }

        const pad = (n: number) => String(n).padStart(2, '0');

        return `${dayText}, ${pad(clock.hour)}:${pad(clock.minute)}`;
    } catch {
        return value;
    }
}

export function FormattedDate({
    value,
    format: formatType = 'short',
    emptyValue,
    className,
}: FormattedDateProps) {
    // Sin fecha: sin emptyValue pinta <EmptyCell />; con emptyValue, texto atenuado (DESIGN.md §9)
    const isEmpty = parseSafeDate(value) === null;

    return (
        <span
            className={
                cn(
                    isEmpty &&
                        emptyValue !== undefined &&
                        'text-muted-foreground',
                    className,
                ) || undefined
            }
        >
            {isEmpty
                ? (emptyValue ?? <EmptyCell />)
                : formatSafeDate(value, formatType, emptyValue)}
        </span>
    );
}

/**
 * Fecha relativa en español («hace 5 minutos»), o `null` si no hay fecha válida. Única forma de mostrar tiempos
 * relativos (campana de alertas, último acceso y actividad de usuarios).
 */
export function formatRelativeTime(
    value: string | null | undefined,
): string | null {
    const date = parseSafeDate(value);

    return date
        ? formatDistanceToNow(date, { addSuffix: true, locale: es })
        : null;
}
