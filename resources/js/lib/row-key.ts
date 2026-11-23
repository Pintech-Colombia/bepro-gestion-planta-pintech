let lastRowKey = 0;

/** Identidad estable de una fila en editores de líneas: los índices cambian al borrar una. */
export function newRowKey(): string {
    lastRowKey += 1;

    return `row-${lastRowKey}`;
}
