import { router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { destroy } from '@/actions/App/Http/Controllers/ProductVariantController';
import { ActiveBadge } from '@/components/active-badge';
import { DetailTable } from '@/components/detail-table';
import { FormattedNumber } from '@/components/formatted-number';
import Heading from '@/components/heading';
import { VariantFormDialog } from '@/components/products/variant-form-dialog';
import type {
    ProductVariantItem,
    VariantLabelOption,
    VariantPackageOption,
    VariantUnitOption,
} from '@/components/products/variant-form-dialog';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useConfirm } from '@/hooks/use-confirm';

type ProductPresentationsTabProps = {
    productId: number;
    variants: ProductVariantItem[];
    canManageVariants: boolean;
    canViewCosts: boolean;
    hasPriceReferenceFormula: boolean;
    units: VariantUnitOption[];
    rawMaterials?: VariantPackageOption[];
    labelMaterials?: VariantLabelOption[];
};

type VariantDialogState = {
    open: boolean;
    variant: ProductVariantItem | null;
    /** Cambia en cada apertura: monta el diálogo de nuevo con los datos de esa presentación. */
    session: number;
};

/** Pestaña «Presentaciones» de la ficha de producto: la tabla de presentaciones y su diálogo de crear o editar. */
export function ProductPresentationsTab({
    productId,
    variants,
    canManageVariants,
    canViewCosts,
    hasPriceReferenceFormula,
    units,
    rawMaterials,
    labelMaterials,
}: ProductPresentationsTabProps) {
    const confirm = useConfirm();
    const [variantDialog, setVariantDialog] = useState<VariantDialogState>({
        open: false,
        variant: null,
        session: 0,
    });

    const openCreate = () => {
        setVariantDialog((current) => ({
            open: true,
            variant: null,
            session: current.session + 1,
        }));
    };

    const openEdit = (variant: ProductVariantItem) => {
        setVariantDialog((current) => ({
            open: true,
            variant,
            session: current.session + 1,
        }));
    };

    const closeDialog = () => {
        setVariantDialog((current) => ({ ...current, open: false }));
    };

    const handleDeleteVariant = async (variant: ProductVariantItem) => {
        if (
            !(await confirm({
                title: `¿Eliminar la presentación «${variant.presentation_label}»?`,
                confirmLabel: 'Eliminar',
                destructive: true,
            }))
        ) {
            return;
        }

        router.delete(
            destroy({ product: productId, variant: variant.id }).url,
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <section className="space-y-4">
            <div className="flex items-start justify-between gap-4">
                <Heading
                    title="Presentaciones"
                    description="Presentaciones de venta: galón, bidón, cuñete, etc. El valor se define en galones."
                />
                {canManageVariants && (
                    <Button size="sm" onClick={openCreate}>
                        Nueva presentación
                    </Button>
                )}
            </div>

            {canManageVariants && (
                <VariantFormDialog
                    key={variantDialog.session}
                    productId={productId}
                    open={variantDialog.open}
                    variant={variantDialog.variant}
                    onClose={closeDialog}
                    canViewCosts={canViewCosts}
                    hasPriceReferenceFormula={hasPriceReferenceFormula}
                    units={units}
                    rawMaterials={rawMaterials}
                    labelMaterials={labelMaterials}
                />
            )}

            <DetailTable
                rows={variants}
                rowKey={(variant) => variant.id}
                caption="Presentaciones"
                columns={[
                    {
                        key: 'presentation',
                        header: 'Presentación',
                        cell: (variant) => (
                            <>
                                {variant.presentation_label}
                                {variant.unit_of_measure && (
                                    <span className="text-muted-foreground">
                                        {` (${variant.unit_of_measure.symbol})`}
                                    </span>
                                )}
                            </>
                        ),
                    },
                    {
                        key: 'code',
                        header: 'Código',
                        cell: (variant) => variant.code,
                        className:
                            'font-mono whitespace-nowrap text-muted-foreground',
                    },
                    ...(canViewCosts
                        ? [
                              {
                                  key: 'price',
                                  header: 'Precio interno',
                                  cell: (variant: ProductVariantItem) =>
                                      variant.current_price ? (
                                          <FormattedNumber
                                              value={variant.current_price}
                                              kind="cost"
                                          />
                                      ) : null,
                                  align: 'right' as const,
                              },
                          ]
                        : []),
                    {
                        key: 'status',
                        header: 'Estado',
                        cell: (variant) => (
                            <ActiveBadge
                                isActive={variant.is_active}
                                feminine
                            />
                        ),
                        align: 'center',
                    },
                    ...(canManageVariants
                        ? [
                              {
                                  key: 'actions',
                                  header: 'Acciones',
                                  cell: (variant: ProductVariantItem) => (
                                      <div className="flex items-center justify-end gap-1">
                                          <Tooltip>
                                              <TooltipTrigger asChild>
                                                  <Button
                                                      type="button"
                                                      variant="ghost"
                                                      size="icon"
                                                      onClick={() =>
                                                          openEdit(variant)
                                                      }
                                                  >
                                                      <Pencil className="size-4" />
                                                      <span className="sr-only">
                                                          Editar presentación
                                                      </span>
                                                  </Button>
                                              </TooltipTrigger>
                                              <TooltipContent>
                                                  Editar presentación
                                              </TooltipContent>
                                          </Tooltip>
                                          <Tooltip>
                                              <TooltipTrigger asChild>
                                                  <Button
                                                      type="button"
                                                      variant="ghost"
                                                      size="icon"
                                                      className="text-destructive-text hover:bg-destructive/10 hover:text-destructive-text"
                                                      onClick={() =>
                                                          handleDeleteVariant(
                                                              variant,
                                                          )
                                                      }
                                                  >
                                                      <Trash2 className="size-4" />
                                                      <span className="sr-only">
                                                          Eliminar presentación
                                                      </span>
                                                  </Button>
                                              </TooltipTrigger>
                                              <TooltipContent>
                                                  Eliminar presentación
                                              </TooltipContent>
                                          </Tooltip>
                                      </div>
                                  ),
                                  align: 'right' as const,
                              },
                          ]
                        : []),
                ]}
                empty="Este producto aún no tiene presentaciones registradas."
            />
        </section>
    );
}
