import { describe, expect, it } from 'vitest';
import { breadcrumbHref, matchesUrlPath } from './navigation';

describe('breadcrumbHref', () => {
    it('keeps the filters when returnTo is the same screen', () => {
        expect(breadcrumbHref('/products?search=x&page=2', '/products')).toBe(
            '/products?search=x&page=2',
        );
    });

    it('ignores returnTo from another module', () => {
        expect(
            breadcrumbHref('/finished-inventory?search=x', '/products'),
        ).toBe('/products');
    });

    it('falls back to the screen without returnTo', () => {
        expect(breadcrumbHref(null, '/products')).toBe('/products');
        expect(breadcrumbHref(undefined, '/products')).toBe('/products');
        expect(breadcrumbHref('', '/products')).toBe('/products');
    });

    it('compares only the path when returnTo carries a nested return_to', () => {
        const fromProduct = `/products/5?return_to=${encodeURIComponent('/products?search=x')}`;

        expect(breadcrumbHref(fromProduct, '/products/5')).toBe(fromProduct);
        expect(breadcrumbHref(fromProduct, '/products')).toBe('/products');
        expect(
            breadcrumbHref(
                `/formulas?return_to=${encodeURIComponent('/products/5')}`,
                '/products/5',
            ),
        ).toBe('/products/5');
    });
});

describe('matchesUrlPath', () => {
    it('exige coincidencia exacta cuando matchChildren es false', () => {
        expect(matchesUrlPath('/settings/profile', '/settings/profile')).toBe(
            true,
        );
        expect(
            matchesUrlPath('/settings/profile', '/settings/profile/edit'),
        ).toBe(false);
    });

    it('coincide con subrutas respetando límite de segmento cuando matchChildren es true', () => {
        expect(
            matchesUrlPath(
                '/settings/catalogs/raw-material-categories',
                '/settings/catalogs/raw-material-categories',
                true,
            ),
        ).toBe(true);
        expect(
            matchesUrlPath(
                '/settings/catalogs/raw-material-categories',
                '/settings/catalogs/raw-material-categories/create',
                true,
            ),
        ).toBe(true);
        expect(
            matchesUrlPath('/settings/profile', '/settings/profile-2fa', true),
        ).toBe(false);
        expect(matchesUrlPath('/', '/settings/profile', true)).toBe(false);
    });
});
