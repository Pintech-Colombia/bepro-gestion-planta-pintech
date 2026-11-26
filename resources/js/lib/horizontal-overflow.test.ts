import { describe, expect, it } from 'vitest';

import {
    measureHorizontalOverflow,
    scrollLeftToRevealItem,
} from '@/lib/horizontal-overflow';

describe('measureHorizontalOverflow', () => {
    it('indica sin desborde cuando todo el contenido cabe en la caja', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: 0,
                scrollWidth: 500,
                clientWidth: 500,
            }),
        ).toEqual({
            overflows: false,
            canScrollLeft: false,
            canScrollRight: false,
        });
    });

    it('absorbe 1 px de redondeo de subpíxeles sin marcar desborde', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: 0,
                scrollWidth: 501,
                clientWidth: 500,
            }),
        ).toEqual({
            overflows: false,
            canScrollLeft: false,
            canScrollRight: false,
        });
    });

    it('marca solo derecha al inicio del desplazamiento', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: 0,
                scrollWidth: 900,
                clientWidth: 360,
            }),
        ).toEqual({
            overflows: true,
            canScrollLeft: false,
            canScrollRight: true,
        });
    });

    it('marca ambos lados en medio del desplazamiento', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: 180,
                scrollWidth: 900,
                clientWidth: 360,
            }),
        ).toEqual({
            overflows: true,
            canScrollLeft: true,
            canScrollRight: true,
        });
    });

    it('marca solo izquierda al llegar al borde derecho (con redondeo subpíxel)', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: 539.5,
                scrollWidth: 900,
                clientWidth: 360,
            }),
        ).toEqual({
            overflows: true,
            canScrollLeft: true,
            canScrollRight: false,
        });
    });

    it('soporta rebote negativo o más allá del máximo (iOS)', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: -15,
                scrollWidth: 900,
                clientWidth: 360,
            }),
        ).toEqual({
            overflows: true,
            canScrollLeft: false,
            canScrollRight: true,
        });

        expect(
            measureHorizontalOverflow({
                scrollLeft: 600,
                scrollWidth: 900,
                clientWidth: 360,
            }),
        ).toEqual({
            overflows: true,
            canScrollLeft: true,
            canScrollRight: false,
        });
    });

    it('no reporta desborde cuando la caja todavía no tiene ancho', () => {
        expect(
            measureHorizontalOverflow({
                scrollLeft: 0,
                scrollWidth: 400,
                clientWidth: 0,
            }),
        ).toEqual({
            overflows: false,
            canScrollLeft: false,
            canScrollRight: false,
        });
    });
});

describe('scrollLeftToRevealItem', () => {
    it('devuelve 0 cuando no hay desborde', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 0,
                clientWidth: 500,
                scrollWidth: 400,
                itemStart: 120,
                itemWidth: 80,
                padding: 32,
            }),
        ).toBe(0);
    });

    it('conserva scrollLeft cuando el elemento ya está completamente visible con su margen', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 100,
                clientWidth: 360,
                scrollWidth: 1000,
                itemStart: 180,
                itemWidth: 120,
                padding: 32,
            }),
        ).toBe(100);
    });

    it('desplaza hacia la derecha cuando la pestaña queda oculta o bajo el desvanecido derecho', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 0,
                clientWidth: 360,
                scrollWidth: 1000,
                itemStart: 320,
                itemWidth: 100,
                padding: 32,
            }),
        ).toBe(92);
    });

    it('topa en el máximo al mostrar la última pestaña', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 0,
                clientWidth: 360,
                scrollWidth: 1000,
                itemStart: 910,
                itemWidth: 86,
                padding: 32,
            }),
        ).toBe(640);
    });

    it('desplaza hacia la izquierda cuando la pestaña queda oculta a la izquierda', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 400,
                clientWidth: 360,
                scrollWidth: 1000,
                itemStart: 150,
                itemWidth: 90,
                padding: 32,
            }),
        ).toBe(118);
    });

    it('topa en 0 al mostrar la primera pestaña', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 400,
                clientWidth: 360,
                scrollWidth: 1000,
                itemStart: 4,
                itemWidth: 60,
                padding: 32,
            }),
        ).toBe(0);
    });

    it('alinea al inicio del elemento cuando es más ancho que la caja', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: 0,
                clientWidth: 200,
                scrollWidth: 1000,
                itemStart: 120,
                itemWidth: 260,
                padding: 32,
            }),
        ).toBe(120);
    });

    it('acota scrollLeft fuera de rango (rebote iOS) y recorta padding excesivo', () => {
        expect(
            scrollLeftToRevealItem({
                scrollLeft: -25,
                clientWidth: 360,
                scrollWidth: 1000,
                itemStart: 40,
                itemWidth: 100,
                padding: 32,
            }),
        ).toBe(0);

        expect(
            scrollLeftToRevealItem({
                scrollLeft: 0,
                clientWidth: 200,
                scrollWidth: 1000,
                itemStart: 180,
                itemWidth: 180,
                padding: 32,
            }),
        ).toBe(170);
    });
});
