type BuildUrlTabHrefOptions = {
    param: string;
    value: string;
    defaultValue: string;
    /** Parámetros a limpiar al cambiar de pestaña (p. ej. `batches_page`). */
    clearParams?: readonly string[];
};

/**
 * Construye la URL relativa para cambiar de pestaña conservando los demás parámetros (p. ej. `return_to`),
 * limpiando los de paginación de la pestaña anterior y omitiendo el parámetro cuando equivale al valor por defecto.
 */
export function buildUrlTabHref(
    currentHref: string,
    { param, value, defaultValue, clearParams = [] }: BuildUrlTabHrefOptions,
): string {
    const url = new URL(currentHref, 'http://localhost');

    for (const key of clearParams) {
        url.searchParams.delete(key);
    }

    if (value === defaultValue) {
        url.searchParams.delete(param);
    } else {
        url.searchParams.set(param, value);
    }

    const query = url.searchParams.toString();

    return query ? `${url.pathname}?${query}` : url.pathname;
}
