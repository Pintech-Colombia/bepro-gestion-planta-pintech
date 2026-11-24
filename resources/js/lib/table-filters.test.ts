import { describe, expect, it } from 'vitest';

import {
    countActiveFilters,
    hasActiveFilters,
    isFilterFieldActive,
} from '@/lib/table-filters';
import type { FilterField } from '@/lib/table-filters';

const fields: FilterField[] = [
    { type: 'text', name: 'search', label: 'Buscar' },
    {
        type: 'select',
        name: 'status',
        label: 'Estado',
        options: [
            { value: 'draft', label: 'Borrador' },
            { value: 'sent', label: 'Enviado' },
        ],
    },
    {
        type: 'select',
        name: 'created_by',
        label: 'Creado por',
        options: [{ value: '1', label: 'Ana' }],
    },
    {
        type: 'date-range',
        nameFrom: 'date_from',
        nameTo: 'date_to',
        label: 'Fecha de cotización',
    },
];

describe('hasActiveFilters', () => {
    it('devuelve false cuando los filtros están vacíos, son nulos o solo tienen espacios', () => {
        expect(hasActiveFilters(null)).toBe(false);
        expect(hasActiveFilters(undefined)).toBe(false);
        expect(hasActiveFilters({})).toBe(false);
        // Laravel serializa un arreglo vacío de PHP (`$request->validated()`) como `[]` en JSON.
        expect(hasActiveFilters([] as unknown as Record<string, string>)).toBe(
            false,
        );
        expect(
            hasActiveFilters({
                search: '',
                status: null,
                created_by: undefined,
                date_from: '   ',
            }),
        ).toBe(false);
    });

    it('ignora el valor centinela __all__ de los selectores', () => {
        expect(hasActiveFilters({ status: '__all__' })).toBe(false);
        expect(hasActiveFilters({ status: '__all__' }, undefined, fields)).toBe(
            false,
        );
    });

    it('detecta filtros activos en texto, select o rango de fechas', () => {
        expect(hasActiveFilters({ search: '120' })).toBe(true);
        expect(hasActiveFilters({ status: 'draft' })).toBe(true);
        expect(hasActiveFilters({ date_from: '2026-11-01' })).toBe(true);
        expect(hasActiveFilters({ date_to: '2026-11-23' })).toBe(true);
    });

    it('respeta defaultFilters al decidir si hay cambios del usuario', () => {
        const defaults = { status: 'unresolved', type: '', severity: '' };

        expect(
            hasActiveFilters(
                { status: 'unresolved', type: '', severity: '' },
                defaults,
            ),
        ).toBe(false);
        expect(
            hasActiveFilters(
                { status: 'all', type: '', severity: '' },
                defaults,
            ),
        ).toBe(true);
        expect(
            hasActiveFilters(
                { status: 'unresolved', type: 'low_stock', severity: '' },
                defaults,
            ),
        ).toBe(true);
    });

    it('evalúa los campos declarados cuando recibe fields', () => {
        expect(
            hasActiveFilters({ unrelated: 'valor' }, undefined, fields),
        ).toBe(false);
        expect(hasActiveFilters({ search: '__all__' }, undefined, fields)).toBe(
            true,
        );
    });
});

describe('countActiveFilters e isFilterFieldActive', () => {
    const secondaryFields = fields.filter((field) => field.type !== 'text');

    it('cuenta un rango de fechas completo o parcial como un solo filtro activo', () => {
        const dateField = fields[3];

        expect(isFilterFieldActive(dateField, {})).toBe(false);
        expect(
            isFilterFieldActive(dateField, { date_from: '2026-11-01' }),
        ).toBe(true);
        expect(
            countActiveFilters(secondaryFields, {
                date_from: '2026-11-01',
                date_to: '2026-11-23',
            }),
        ).toBe(1);
    });

    it('cuenta solo los filtros secundarios activos para el botón del celular', () => {
        expect(
            countActiveFilters(secondaryFields, {
                search: 'pintura',
                status: 'sent',
                created_by: '__all__',
                date_from: '2026-11-01',
                date_to: '2026-11-23',
            }),
        ).toBe(2);
    });

    it('cuenta cero cuando los filtros coinciden con defaultFilters', () => {
        const alertFields: FilterField[] = [
            {
                type: 'select',
                name: 'status',
                label: 'Estado',
                options: [{ value: 'unresolved', label: 'Activas' }],
                allValue: 'all',
            },
            {
                type: 'select',
                name: 'severity',
                label: 'Urgencia',
                options: [{ value: 'alta', label: 'Alta' }],
            },
        ];
        const defaults = { status: 'unresolved', severity: '' };

        expect(
            countActiveFilters(
                alertFields,
                { status: 'unresolved', severity: '' },
                defaults,
            ),
        ).toBe(0);
        expect(
            countActiveFilters(
                alertFields,
                { status: 'all', severity: 'alta' },
                defaults,
            ),
        ).toBe(2);
    });

    it('cuenta un filtro que llega como número o booleano', () => {
        expect(hasActiveFilters({ warehouse_id: 5 })).toBe(true);
        expect(hasActiveFilters({ include_inactive: true })).toBe(true);
        expect(hasActiveFilters({ warehouse_id: null })).toBe(false);
    });
});
