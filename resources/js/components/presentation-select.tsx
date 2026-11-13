import type { FormFieldControlProps } from '@/components/form-field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type PresentationOption = { id: string | number; label: string };

type PresentationSelectProps = {
    options: PresentationOption[];
    /** `''` o `null`: sin elegir. */
    value: string | number | null;
    /** Recibe siempre el id como texto. */
    onChange: (value: string) => void;
    /** Por ejemplo, mientras no haya producto elegido. */
    disabled?: boolean;
    /** Lo que entrega `FormField` (id y `aria-*`): va al `SelectTrigger`. */
    control?: FormFieldControlProps;
    /** Nombre del campo cuando no está dentro de un `FormField`. */
    'aria-label'?: string;
};

/**
 * La presentación de un producto (DESIGN.md §8): un `Select` sin buscador, porque un producto tiene pocas. No elige nada
 * por su cuenta.
 */
export function PresentationSelect({
    options,
    value,
    onChange,
    disabled = false,
    control,
    'aria-label': ariaLabel,
}: PresentationSelectProps) {
    const noOptions = !disabled && options.length === 0;
    const placeholder = disabled
        ? 'Selecciona producto primero'
        : noOptions
          ? 'Sin presentaciones'
          : 'Selecciona presentación';

    return (
        <Select
            value={value === null ? '' : String(value)}
            // Radix puede avisar un valor vacío sin que el usuario elija nada (p. ej. el autocompletado del navegador):
            // se ignora, para no borrar la presentación ni lo que depende de ella (el precio de la cotización).
            onValueChange={(next) => {
                if (next !== '') {
                    onChange(next);
                }
            }}
            disabled={disabled || noOptions}
        >
            <SelectTrigger {...control} aria-label={ariaLabel}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
