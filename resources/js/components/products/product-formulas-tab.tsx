import { Link } from '@inertiajs/react';
import { BadgeDollarSign } from 'lucide-react';

import { ActiveBadge } from '@/components/active-badge';
import { DetailTable } from '@/components/detail-table';
import { FormattedDate } from '@/components/formatted-date';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { withReturnTo } from '@/lib/navigation';
import {
    create as formulasCreate,
    show as formulasShow,
} from '@/routes/formulas';
import { show as productsShow } from '@/routes/products';

export type ProductFormulaItem = {
    id: number;
    version: number;
    name: string | null;
    is_active: boolean;
    is_price_reference: boolean;
    notes: string | null;
    created_at: string;
    created_by?: { name: string } | null;
};

type ProductFormulasTabProps = {
    productId: number;
    formulas: ProductFormulaItem[];
    canCreateFormulas: boolean;
    returnTo?: string | null;
};

/** Pestaña «Fórmulas» de la ficha de producto (solo con permiso para ver fórmulas). */
export function ProductFormulasTab({
    productId,
    formulas,
    canCreateFormulas,
    returnTo,
}: ProductFormulasTabProps) {
    // La fórmula con que se costea el producto (puede haber otras activas para producir).
    const priceReferenceFormula = formulas.find((f) => f.is_price_reference);

    return (
        <section className="space-y-4">
            <div className="flex items-start justify-between gap-4">
                <Heading
                    title="Fórmulas"
                    description={
                        priceReferenceFormula
                            ? `Referencia de precio: v${priceReferenceFormula.version}${priceReferenceFormula.name ? ` · ${priceReferenceFormula.name}` : ''}`
                            : 'Sin fórmula de referencia de precio'
                    }
                />
                {canCreateFormulas && (
                    <Button size="sm" asChild>
                        <Link
                            href={
                                formulasCreate({
                                    query: {
                                        product_id: productId,
                                        return_to: withReturnTo(
                                            productsShow(
                                                { product: productId },
                                                {
                                                    query: {
                                                        section: 'formulas',
                                                    },
                                                },
                                            ).url,
                                            returnTo,
                                        ),
                                    },
                                }).url
                            }
                        >
                            Nueva fórmula
                        </Link>
                    </Button>
                )}
            </div>

            <DetailTable
                rows={formulas}
                rowKey={(formula) => formula.id}
                caption="Fórmulas"
                columns={[
                    {
                        key: 'version',
                        header: 'Versión',
                        cell: (formula) => (
                            <>
                                <div className="font-mono">
                                    v{formula.version}
                                </div>
                                {formula.name && (
                                    <div className="text-xs font-normal text-muted-foreground">
                                        {formula.name}
                                    </div>
                                )}
                            </>
                        ),
                    },
                    {
                        key: 'status',
                        header: 'Estado',
                        cell: (formula) => (
                            <div className="flex flex-wrap gap-1.5">
                                <ActiveBadge
                                    isActive={formula.is_active}
                                    feminine
                                />
                                {formula.is_price_reference && (
                                    <Badge variant="outline">
                                        <BadgeDollarSign aria-hidden="true" />
                                        Referencia de precio
                                    </Badge>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'notes',
                        header: 'Notas',
                        cell: (formula) => formula.notes,
                        className: 'text-muted-foreground',
                    },
                    {
                        key: 'creator',
                        header: 'Creada por',
                        cell: (formula) => formula.created_by?.name ?? null,
                        className: 'text-muted-foreground',
                    },
                    {
                        key: 'date',
                        header: 'Fecha',
                        cell: (formula) => (
                            <FormattedDate
                                value={formula.created_at}
                                format="datetime"
                            />
                        ),
                        className: 'whitespace-nowrap text-muted-foreground',
                    },
                    {
                        key: 'actions',
                        header: 'Acciones',
                        cell: (formula) => (
                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={
                                        formulasShow({
                                            formula: formula.id,
                                        }).url
                                    }
                                >
                                    Ver
                                </Link>
                            </Button>
                        ),
                        align: 'right',
                    },
                ]}
                empty={
                    canCreateFormulas
                        ? 'No hay fórmulas registradas. Crea la primera con «Nueva fórmula».'
                        : 'No hay fórmulas registradas.'
                }
            />
        </section>
    );
}
