import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { NavTabs } from '@/components/nav-tabs';
import { PageHeader } from '@/components/page-header';
import { filterNavItemsByPermissions } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as productCategoriesIndex } from '@/routes/catalogs/product-categories';
import { index as rawMaterialCategoriesIndex } from '@/routes/catalogs/raw-material-categories';
import { index as rawMaterialFamiliesIndex } from '@/routes/catalogs/raw-material-families';
import { index as shrinkWrapTypesIndex } from '@/routes/catalogs/shrink-wrap-types';
import { index as suppliersIndex } from '@/routes/catalogs/suppliers';
import { index as unitsOfMeasureIndex } from '@/routes/catalogs/units-of-measure';
import { edit } from '@/routes/profile';
import type { NavGroup } from '@/types';

const settingsNavGroups: NavGroup[] = [
    {
        label: 'Cuenta',
        items: [
            {
                title: 'Perfil',
                href: edit().url,
                icon: null,
            },
            {
                title: 'Apariencia',
                href: editAppearance().url,
                icon: null,
            },
        ],
    },
    {
        // Catálogos del sistema (docs/MATRIZ_RBAC.md §3): solo con `catalogs.view`.
        label: 'Catálogos',
        items: [
            {
                title: 'Unidades de medida',
                href: unitsOfMeasureIndex().url,
                icon: null,
                allowedPermissions: ['catalogs.view'],
            },
            {
                title: 'Familias de MP',
                href: rawMaterialFamiliesIndex().url,
                icon: null,
                allowedPermissions: ['catalogs.view'],
            },
            {
                title: 'Categorías de MP',
                href: rawMaterialCategoriesIndex().url,
                icon: null,
                allowedPermissions: ['catalogs.view'],
            },
            {
                title: 'Categorías de producto',
                href: productCategoriesIndex().url,
                icon: null,
                allowedPermissions: ['catalogs.view'],
            },
            {
                title: 'Tipos de termoencogido',
                href: shrinkWrapTypesIndex().url,
                icon: null,
                allowedPermissions: ['catalogs.view'],
            },
            {
                title: 'Proveedores',
                href: suppliersIndex().url,
                icon: null,
                allowedPermissions: ['catalogs.view'],
            },
        ],
    },
];

type SettingsLayoutProps = PropsWithChildren<{
    /** Las páginas con tablas (catálogos) usan todo el ancho disponible. */
    wide?: boolean;
}>;

export default function SettingsLayout({
    children,
    wide = false,
}: SettingsLayoutProps) {
    const userPermissions = usePage().props.auth.user?.permissions ?? [];

    const groups = settingsNavGroups
        .map((group) => ({
            ...group,
            items: filterNavItemsByPermissions(group.items, userPermissions),
        }))
        .filter((group) => group.items.length > 0);
    const showsCatalogs = groups.length > 1;

    return (
        <div className="min-w-0 space-y-6 px-4 py-6 md:px-6">
            <PageHeader
                title="Configuración"
                description={
                    showsCatalogs
                        ? 'Gestiona tu cuenta y los catálogos del sistema'
                        : 'Gestiona tu perfil y la configuración de tu cuenta'
                }
            />

            <NavTabs groups={groups} label="Configuración" />

            <section
                className={cn(
                    'space-y-10 rounded-lg border border-border bg-card p-6 shadow-xs',
                    !wide && 'max-w-2xl',
                )}
            >
                {children}
            </section>
        </div>
    );
}
