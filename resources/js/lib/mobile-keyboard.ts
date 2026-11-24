/**
 * Detecta si un elemento enfocado abre el teclado virtual en el celular (DESIGN.md §10).
 *
 * Se mantiene sin dependencia del DOM real para probarse en Vitest sin entorno de navegador: acepta tanto un
 * `Element` real (`document.activeElement`) como un objeto plano en pruebas.
 */

export type FocusableElementLike = {
    tagName?: string;
    type?: string;
    disabled?: boolean;
    readOnly?: boolean;
    isContentEditable?: boolean;
    getAttribute?: (name: string) => string | null;
    hasAttribute?: (name: string) => boolean;
};

const TEXT_INPUT_TYPES = new Set([
    'text',
    'search',
    'tel',
    'email',
    'number',
    'url',
    'password',
]);

/**
 * Devuelve `true` solo cuando el elemento abre el teclado virtual: `<input>` de texto (o sin `type`), `<textarea>` o
 * `[contenteditable]`, siempre que no esté `disabled` ni `readOnly`. Botones, casillas, radios y disparadores de
 * `Select` (Radix) devuelven `false`.
 */
export function opensKeyboard(
    element: FocusableElementLike | null | undefined,
): boolean {
    if (!element) {
        return false;
    }

    const isDisabled =
        Boolean(element.disabled) ||
        element.hasAttribute?.('disabled') === true;
    const isReadOnly =
        Boolean(element.readOnly) ||
        element.hasAttribute?.('readonly') === true;

    if (isDisabled || isReadOnly) {
        return false;
    }

    const tagName = element.tagName?.toUpperCase();

    if (tagName === 'TEXTAREA') {
        return true;
    }

    if (tagName === 'INPUT') {
        const rawType = element.getAttribute?.('type') ?? element.type;

        if (
            rawType === null ||
            rawType === undefined ||
            rawType.trim() === ''
        ) {
            return true;
        }

        return TEXT_INPUT_TYPES.has(rawType.trim().toLowerCase());
    }

    if (element.isContentEditable === true) {
        return true;
    }

    if (element.isContentEditable === false) {
        return false;
    }

    const contentEditable = element.getAttribute?.('contenteditable');

    if (contentEditable !== null && contentEditable !== undefined) {
        return contentEditable.trim().toLowerCase() !== 'false';
    }

    return false;
}
