import { Head, Link, router } from '@inertiajs/react';

import { destroy as destroyProduct } from '@/actions/App/Http/Controllers/ProductController';
import { ActiveBadge } from '@/components/active-badge';
import { DetailItem, DetailList } from '@/components/detail-list';
import { FormattedNumber } from '@/components/formatted-number';
import { PageHeader } from '@/components/page-header';
import type { ProductDocumentTypeOption } from '@/components/products/document-upload-dialog';
import { ProductFormulasTab } from '@/components/products/product-formulas-tab';
import type { ProductFormulaItem } from '@/components/products/product-formulas-tab';
import { ProductPresentationsTab } from '@/components/products/product-presentations-tab';
import { ProductQualityTab } from '@/components/products/product-quality-tab';
import type {
    CertificateRange,
    ProductDocumentItem,
} from '@/components/products/product-quality-tab';
import type {
    ProductVariantItem,
    VariantLabelOption,
    VariantPackageOption,
    VariantUnitOption,
} from '@/components/products/variant-form-dialog';
import { SectionTabsList, SectionTabsTrigger } from '@/components/section-tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { useConfirm } from '@/hooks/use-confirm';
import { useUrlTab } from '@/hooks/use-url-tab';
import { formatKind, formatNumber, formatQualityRange } from '@/lib/formatters';
import { breadcrumbHref } from '@/lib/navigation';
import { index as finishedInventoryIndex } from '@/routes/finished-inventory';
import {
    edit as productsEdit,
    index as productsIndex,
    show as productsShow,
} from '@/routes/products';

const SECTION_TABS = ['presentations', 'formulas', 'quality'] as const;
const SECTION_RELOAD_ONLY = ['section'];

type Section = (typeof SECTION_TABS)[number];

type Props = {
    returnTo?: string | null;
    section: Section;
    hasPriceReferenceFormula: boolean;
    product: {
        id: number;
        name: string;
        is_active: boolean;
        category?: { name: string } | null;
        unit_of_measure?: { name: string; symbol: string } | null;
        current_price?: string | null;
        reference_density_kg_per_gallon: string | null;
        description?: string | null;
        quality_viscosity_lower?: number | string | null;
        quality_viscosity_upper?: number | string | null;
        quality_fineness_lower?: number | string | null;
        quality_fineness_upper?: number | string | null;
        quality_solids_lower?: number | string | null;
        quality_solids_upper?: number | string | null;
        variants?: ProductVariantItem[];
        formulas?: ProductFormulaItem[];
        product_documents?: ProductDocumentItem[];
    };
    can: {
        update: boolean;
        delete: boolean;
        manageVariants: boolean;
        manageDocuments: boolean;
        downloadDocuments: boolean;
        viewCosts: boolean;
        viewFormulas: boolean;
        createFormulas: boolean;
        viewFinishedInventory: boolean;
    };
    documentTypes: ProductDocumentTypeOption[];
    units: VariantUnitOption[];
    rawMaterials?: VariantPackageOption[];
    labelMaterials?: VariantLabelOption[];
};

export default function ProductsShow({
    returnTo,
    section,
    hasPriceReferenceFormula,
    product,
    can,
    documentTypes,
    units,
    rawMaterials,
    labelMaterials,
}: Props) {
    const confirm = useConfirm();

    // Los datos de las tres pestañas llegan en la carga: al cambiar solo se pide la pestaña validada por el servidor.
    const { tab: activeSection, setTab: setActiveSection } = useUrlTab({
        param: 'section',
        tabs: SECTION_TABS,
        defaultTab: 'presentations',
        value: section,
        reloadOnly: SECTION_RELOAD_ONLY,
    });

    const certificateRanges: CertificateRange[] = [
        {
            label: 'Viscosidad (KU)',
            range: formatQualityRange(
                product.quality_viscosity_lower,
                product.quality_viscosity_upper,
            ),
        },
        {
            label: 'Molienda (HG)',
            range: formatQualityRange(
                product.quality_fineness_lower,
                product.quality_fineness_upper,
            ),
        },
        {
            label: 'Sólidos (%)',
            range: formatQualityRange(
                product.quality_solids_lower,
                product.quality_solids_upper,
            ),
        },
    ].filter((item): item is CertificateRange => item.range !== null);

    const handleDelete = async () => {
        if (
            !(await confirm({
                title: `¿Eliminar el producto ${product.name}?`,
                confirmLabel: 'Eliminar',
                destructive: true,
            }))
        ) {
            return;
        }

        router.delete(destroyProduct({ product: product.id }).url);
    };

    const variants = product.variants ?? [];
    const formulas = product.formulas ?? [];

    return (
        <>
            <Head title={product.name} />
            <div className="space-y-6 p-6">
                <PageHeader
                    title={product.name}
                    badge={<ActiveBadge isActive={product.is_active} />}
                    actions={
                        <>
                            {can.viewFinishedInventory && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={
                                            finishedInventoryIndex({
                                                query: {
                                                    product_id: product.id,
                                                },
                                            }).url
                                        }
                                    >
                                        Ver inventario
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={
                                            productsEdit({
                                                product: product.id,
                                            }).url
                                        }
                                    >
                                        Editar
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <Button
                                    variant="destructive"
                                    onClick={handleDelete}
                                >
                                    Eliminar
                                </Button>
                            )}
                        </>
                    }
                />

                {/* Info del producto */}
                <Card>
                    <CardContent>
                        <DetailList columns={3}>
                            <DetailItem
                                label="Categoría"
                                value={product.category?.name}
                            />
                            <DetailItem
                                label="Unidad de medida"
                                value={
                                    product.unit_of_measure &&
                                    `${product.unit_of_measure.name} (${product.unit_of_measure.symbol})`
                                }
                            />
                            <DetailItem
                                label="Peso por galón de referencia"
                                value={
                                    // Medida: los 4 decimales de su columna, sin tipo (DESIGN.md §7).
                                    product.reference_density_kg_per_gallon !==
                                    null
                                        ? `${formatNumber(product.reference_density_kg_per_gallon, { maxDecimals: 4, trimTrailingZeros: true })} kg/gal`
                                        : null
                                }
                            />
                            {can.viewCosts && (
                                <DetailItem
                                    label="Precio interno"
                                    value={
                                        <FormattedNumber
                                            value={product.current_price}
                                            kind="cost"
                                            emptyValue="No asignado"
                                        />
                                    }
                                />
                            )}
                        </DetailList>
                    </CardContent>
                </Card>

                {product.description && (
                    <Card>
                        <CardContent>
                            <DetailList columns={1}>
                                <DetailItem
                                    label="Descripción"
                                    value={product.description}
                                    valueClassName="whitespace-pre-wrap"
                                />
                            </DetailList>
                        </CardContent>
                    </Card>
                )}

                <Tabs
                    value={activeSection}
                    onValueChange={setActiveSection}
                    className="space-y-4"
                >
                    <SectionTabsList>
                        <SectionTabsTrigger value="presentations">
                            Presentaciones (
                            {formatKind(variants.length, 'units')})
                        </SectionTabsTrigger>
                        {can.viewFormulas && (
                            <SectionTabsTrigger value="formulas">
                                Fórmulas ({formatKind(formulas.length, 'units')}
                                )
                            </SectionTabsTrigger>
                        )}
                        <SectionTabsTrigger value="quality">
                            Calidad y documentos
                        </SectionTabsTrigger>
                    </SectionTabsList>

                    <TabsContent value="presentations">
                        <ProductPresentationsTab
                            productId={product.id}
                            variants={variants}
                            canManageVariants={can.manageVariants}
                            canViewCosts={can.viewCosts}
                            hasPriceReferenceFormula={hasPriceReferenceFormula}
                            units={units}
                            rawMaterials={rawMaterials}
                            labelMaterials={labelMaterials}
                        />
                    </TabsContent>

                    {can.viewFormulas && (
                        <TabsContent value="formulas">
                            <ProductFormulasTab
                                productId={product.id}
                                formulas={formulas}
                                canCreateFormulas={can.createFormulas}
                                returnTo={returnTo}
                            />
                        </TabsContent>
                    )}

                    <TabsContent value="quality">
                        <ProductQualityTab
                            productId={product.id}
                            certificateRanges={certificateRanges}
                            documents={product.product_documents ?? []}
                            documentTypes={documentTypes}
                            canManageDocuments={can.manageDocuments}
                            canDownloadDocuments={can.downloadDocuments}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10); el listado conserva sus filtros con `returnTo`.
ProductsShow.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: 'Productos',
            href: breadcrumbHref(props.returnTo, productsIndex().url),
        },
        {
            title: props.product.name,
            href: productsShow({ product: props.product.id }),
        },
    ],
});
