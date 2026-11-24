/**
 * Lógica pura compartida por `<DataTableFilters>` y los listados para saber si hay filtros activos y cuántos
 * (DESIGN.md §9).
 */

export type FilterValue = string | null | undefined;
export type FilterValues = Record<string, FilterValue>;

/**
 * Lo que reciben las funciones que cuentan filtros activos. Los filtros llegan del servidor como texto
 * (`$request->validated()`), pero un `prepareForValidation` puede convertir uno a número o booleano: también cuentan.
 */
type AnyFilterValues = Readonly<Record<string, unknown>>;

export type FilterField =
    | {
          type: 'text';
          name: string;
          label: string;
          placeholder?: string;
      }
    | {
          type: 'select';
          name: string;
          label: string;
          options: { value: string; label: string }[];
          allValue?: string;
      }
    | {
          type: 'date-range';
          nameFrom: string;
          nameTo: string;
          label: string;
      };

function normalizeFilterValue(
    value: unknown,
    treatAllSentinel: boolean,
): string {
    if (value === null || value === undefined) {
        return '';
    }

    const trimmed = String(value).trim();

    if (trimmed === '' || (treatAllSentinel && trimmed === '__all__')) {
        return '';
    }

    return trimmed;
}

/** Indica si un campo de filtro tiene un valor distinto de su valor por defecto. */
export function isFilterFieldActive(
    field: FilterField,
    filters: AnyFilterValues | null | undefined,
    defaultFilters?: AnyFilterValues,
): boolean {
    if (field.type === 'date-range') {
        const defaultFrom = normalizeFilterValue(
            defaultFilters?.[field.nameFrom],
            false,
        );
        const defaultTo = normalizeFilterValue(
            defaultFilters?.[field.nameTo],
            false,
        );
        const currentFrom = normalizeFilterValue(
            filters?.[field.nameFrom],
            false,
        );
        const currentTo = normalizeFilterValue(filters?.[field.nameTo], false);

        return currentFrom !== defaultFrom || currentTo !== defaultTo;
    }

    const treatAllSentinel = field.type === 'select';
    const defaultValue = normalizeFilterValue(
        defaultFilters?.[field.name],
        treatAllSentinel,
    );
    const currentValue = normalizeFilterValue(
        filters?.[field.name],
        treatAllSentinel,
    );

    return currentValue !== defaultValue;
}

/** Cuenta cuántos campos de filtro están activos (un rango de fechas cuenta como uno solo). */
export function countActiveFilters(
    fields: readonly FilterField[],
    filters: AnyFilterValues | null | undefined,
    defaultFilters?: AnyFilterValues,
): number {
    return fields.filter((field) =>
        isFilterFieldActive(field, filters, defaultFilters),
    ).length;
}

/**
 * Indica si el listado tiene al menos un filtro activo distinto de `defaultFilters`.
 * Cuando se pasan `fields`, evalúa exactamente esos campos; sin ellos, revisa todas las llaves de `filters` y
 * `defaultFilters` (ignorando vacíos, solo espacios y el centinela `__all__`).
 */
export function hasActiveFilters(
    filters: AnyFilterValues | null | undefined,
    defaultFilters?: AnyFilterValues,
    fields?: readonly FilterField[],
): boolean {
    if (fields) {
        return countActiveFilters(fields, filters, defaultFilters) > 0;
    }

    const keys = new Set([
        ...Object.keys(filters ?? {}),
        ...Object.keys(defaultFilters ?? {}),
    ]);

    for (const key of keys) {
        const currentValue = normalizeFilterValue(filters?.[key], true);
        const defaultValue = normalizeFilterValue(defaultFilters?.[key], true);

        if (currentValue !== defaultValue) {
            return true;
        }
    }

    return false;
}
