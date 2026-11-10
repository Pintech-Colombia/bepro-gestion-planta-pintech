import { describe, expect, it } from 'vitest';
import { closesMobileSidebar } from './sidebar-link-tap';
import type { SidebarTap, SidebarTapLink } from './sidebar-link-tap';

const ORIGIN = 'https://erp.example.test';

const tap = (overrides: Partial<SidebarTap> = {}): SidebarTap => ({
    altKey: false,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    ...overrides,
});

const link = (
    overrides: Partial<NonNullable<SidebarTapLink>> = {},
): SidebarTapLink => ({
    target: '',
    download: false,
    origin: ORIGIN,
    ...overrides,
});

describe('closesMobileSidebar', () => {
    it('closes on a plain tap on an internal link', () => {
        expect(closesMobileSidebar(tap(), link(), ORIGIN)).toBe(true);
        expect(
            closesMobileSidebar(tap(), link({ target: '_self' }), ORIGIN),
        ).toBe(true);
    });

    it('stays open when the tap is not on a link', () => {
        expect(closesMobileSidebar(tap(), null, ORIGIN)).toBe(false);
    });

    it.each([
        ['Ctrl', { ctrlKey: true }],
        ['⌘', { metaKey: true }],
        ['Mayús', { shiftKey: true }],
        ['Alt', { altKey: true }],
    ])('stays open with %s (another tab or window)', (_, overrides) => {
        expect(closesMobileSidebar(tap(overrides), link(), ORIGIN)).toBe(false);
    });

    it('stays open for links that leave this tab or this app', () => {
        expect(
            closesMobileSidebar(tap(), link({ target: '_blank' }), ORIGIN),
        ).toBe(false);
        expect(
            closesMobileSidebar(tap(), link({ download: true }), ORIGIN),
        ).toBe(false);
        expect(
            closesMobileSidebar(
                tap(),
                link({ origin: 'https://otro.example.test' }),
                ORIGIN,
            ),
        ).toBe(false);
        // `mailto:` y `tel:` tienen origen «null».
        expect(
            closesMobileSidebar(tap(), link({ origin: 'null' }), ORIGIN),
        ).toBe(false);
    });
});
