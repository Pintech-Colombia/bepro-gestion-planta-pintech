import { describe, expect, it } from 'vitest';

import { opensKeyboard } from '@/lib/mobile-keyboard';
import type { FocusableElementLike } from '@/lib/mobile-keyboard';

function mockElement(
    tagName: string,
    options: {
        attrs?: Record<string, string>;
        type?: string;
        disabled?: boolean;
        readOnly?: boolean;
        isContentEditable?: boolean;
    } = {},
): FocusableElementLike {
    const attrs = options.attrs ?? {};

    return {
        tagName,
        type: options.type,
        disabled: options.disabled,
        readOnly: options.readOnly,
        isContentEditable: options.isContentEditable,
        getAttribute: (name: string) =>
            Object.prototype.hasOwnProperty.call(attrs, name)
                ? attrs[name]
                : null,
        hasAttribute: (name: string) =>
            Object.prototype.hasOwnProperty.call(attrs, name),
    };
}

describe('opensKeyboard', () => {
    it('devuelve false sin elemento enfocado', () => {
        expect(opensKeyboard(null)).toBe(false);
        expect(opensKeyboard(undefined)).toBe(false);
    });

    it('detecta inputs de texto y equivalentes (incluidos los que no declaran type)', () => {
        for (const type of [
            'text',
            'search',
            'tel',
            'email',
            'number',
            'url',
            'password',
            'TEXT',
        ]) {
            expect(
                opensKeyboard(mockElement('INPUT', { attrs: { type } })),
            ).toBe(true);
        }

        // Sin atributo `type` (por defecto es texto, p. ej. buscador de Combobox o DecimalInput).
        expect(opensKeyboard(mockElement('INPUT'))).toBe(true);
        expect(
            opensKeyboard(mockElement('INPUT', { attrs: { type: '' } })),
        ).toBe(true);
        expect(opensKeyboard(mockElement('input', { type: 'search' }))).toBe(
            true,
        );
    });

    it('ignora inputs que no abren el teclado virtual', () => {
        for (const type of [
            'checkbox',
            'radio',
            'button',
            'submit',
            'reset',
            'file',
            'range',
            'color',
            'hidden',
        ]) {
            expect(
                opensKeyboard(mockElement('INPUT', { attrs: { type } })),
            ).toBe(false);
        }
    });

    it('detecta textarea y elementos contenteditable', () => {
        expect(opensKeyboard(mockElement('TEXTAREA'))).toBe(true);
        expect(
            opensKeyboard(
                mockElement('DIV', { attrs: { contenteditable: '' } }),
            ),
        ).toBe(true);
        expect(
            opensKeyboard(
                mockElement('DIV', { attrs: { contenteditable: 'true' } }),
            ),
        ).toBe(true);
        expect(
            opensKeyboard(
                mockElement('DIV', {
                    attrs: { contenteditable: 'plaintext-only' },
                }),
            ),
        ).toBe(true);
        expect(
            opensKeyboard(mockElement('DIV', { isContentEditable: true })),
        ).toBe(true);
        expect(
            opensKeyboard(
                mockElement('DIV', { attrs: { contenteditable: 'false' } }),
            ),
        ).toBe(false);
        expect(
            opensKeyboard(mockElement('DIV', { isContentEditable: false })),
        ).toBe(false);
    });

    it('devuelve false para botones, selectores de Radix y contenedores comunes', () => {
        expect(opensKeyboard(mockElement('BUTTON'))).toBe(false);
        expect(
            opensKeyboard(
                mockElement('BUTTON', { attrs: { role: 'combobox' } }),
            ),
        ).toBe(false);
        expect(opensKeyboard(mockElement('A'))).toBe(false);
        expect(opensKeyboard(mockElement('BODY'))).toBe(false);
        expect(opensKeyboard(mockElement('DIV'))).toBe(false);
    });

    it('devuelve false para campos disabled o readOnly', () => {
        expect(
            opensKeyboard(
                mockElement('INPUT', {
                    attrs: { type: 'text' },
                    disabled: true,
                }),
            ),
        ).toBe(false);
        expect(
            opensKeyboard(
                mockElement('INPUT', {
                    attrs: { type: 'text', disabled: '' },
                }),
            ),
        ).toBe(false);
        expect(
            opensKeyboard(
                mockElement('INPUT', {
                    attrs: { type: 'text' },
                    readOnly: true,
                }),
            ),
        ).toBe(false);
        expect(
            opensKeyboard(
                mockElement('INPUT', {
                    attrs: { type: 'text', readonly: '' },
                }),
            ),
        ).toBe(false);
        expect(opensKeyboard(mockElement('TEXTAREA', { readOnly: true }))).toBe(
            false,
        );
        expect(opensKeyboard(mockElement('TEXTAREA', { disabled: true }))).toBe(
            false,
        );
    });
});
