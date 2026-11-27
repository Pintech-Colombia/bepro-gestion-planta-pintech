import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import {
    complete as productionOrderComplete,
    startProduction as productionOrderStartProduction,
    submitForReview as productionOrderSubmitForReview,
} from '@/actions/App/Http/Controllers/ProductionOrderController';
import { OpClosingRead } from '@/components/production/op-closing-read';
import { OpClosingSection } from '@/components/production/op-closing-section';
import { OpConsumptionRead } from '@/components/production/op-consumption-read';
import { OpConsumptionSection } from '@/components/production/op-consumption-section';
import {
    OpRejectDialog,
    OpStartDialog,
    OpSubmitDialog,
} from '@/components/production/op-dialogs';
import { OpKeyFigures } from '@/components/production/op-key-figures';
import { OpPackagingRead } from '@/components/production/op-packaging-read';
import { OpPackagingSection } from '@/components/production/op-packaging-section';
import { OpQualityRead } from '@/components/production/op-quality-read';
import { OpQualitySection } from '@/components/production/op-quality-section';
import { OpSummaryPanel } from '@/components/production/op-summary-panel';
import { OrderHeader } from '@/components/production/order-header';
import { ProductsToUseCard } from '@/components/production/products-to-use-card';
import { QrLine } from '@/components/production/qr-line';
import {
    useBatchUsageSync,
    useProductLineUsageSync,
} from '@/hooks/use-batch-usage-sync';
import { usePackagingSync } from '@/hooks/use-packaging-sync';
import { useProductionCostPreview } from '@/hooks/use-production-cost-preview';
import { useProductionOrderFigures } from '@/hooks/use-production-order-figures';
import { formatQualityRange } from '@/lib/formatters';
import { breadcrumbHref } from '@/lib/navigation';
import { parseDecimal } from '@/lib/numeric-input';
import {
    initialProductionOrderFormData,
    normalizeProductionOrderFormData,
} from '@/lib/production-order-form';
import {
    index as productionOrdersIndex,
    show as productionOrdersShow,
} from '@/routes/production-orders';
import type {
    ProductionOrderBatchUsageRow,
    ProductionOrderFormData,
    ProductionOrderShowProps,
} from '@/types/production-orders';

export default function ProductionOrderShow({
    order,
    rawMaterials,
    labelMaterials,
    availableVariants,
    qualitySigners,
    can,
}: ProductionOrderShowProps) {
    const orderDetails = useMemo(() => order.details ?? [], [order.details]);
    const orderPackagingPlans = useMemo(
        () => order.packaging_plans ?? [],
        [order.packaging_plans],
    );
    const lineAdjustments = useMemo(
        () => order.line_adjustments ?? [],
        [order.line_adjustments],
    );
    const batchSources = useMemo(
        () => order.batch_consumptions ?? [],
        [order.batch_consumptions],
    );
    const productLines = useMemo(
        () => order.product_lines ?? [],
        [order.product_lines],
    );
    // Las fuentes de «Productos a usar» con sus kilos y unidades: al cambiar, la vista previa se vuelve a pedir.
    const sourcesKey = [
        ...(order.remnant_consumptions ?? []).map(
            (source) => `saldo-${source.id}:${source.quantity_kg}`,
        ),
        ...batchSources.map((source) => `lote-${source.id}:${source.units}`),
    ].join(',');

    const isCompleted = order.status === 'completed';
    const isCancelled = order.status === 'cancelled';
    const isPending = order.status === 'pending';
    const isPendingReview = order.status === 'pending_review';
    // Cancelada: cerrada, nada se edita (antes mostraba los campos sin botón para guardar).
    const isFormReadOnly =
        isPending ||
        isCompleted ||
        isCancelled ||
        (isPendingReview && !can.complete);
    // Completada, cancelada, o en revisión para quien no aprueba: ficha de lectura (sin campos apagados). Pendiente
    // sigue con el plan y «Iniciar producción».
    const isReadSheet = isFormReadOnly && !isPending;
    const hasOrderData =
        orderDetails.length > 0 || orderPackagingPlans.length > 0;

    const submitMode =
        can.submitForReview && !isPendingReview ? 'review' : 'complete';

    const submitLabel =
        submitMode === 'review'
            ? 'Enviar a revisión'
            : isPendingReview
              ? 'Aprobar y cerrar'
              : 'Finalizar producción';

    const processingLabel =
        submitMode === 'review' ? 'Enviando...' : 'Finalizando...';

    const confirmTitle =
        submitMode === 'review'
            ? '¿Enviar orden a revisión?'
            : isPendingReview
              ? '¿Aprobar y cerrar la orden?'
              : '¿Finalizar orden de producción?';

    const confirmDescription =
        submitMode === 'review'
            ? 'Los datos quedarán registrados y producción deberá validar el cierre definitivo. No se moverá inventario todavía.'
            : 'Esta acción actualizará los inventarios de forma irreversible. Una vez finalizada, no se podrán realizar más ajustes a la orden.';

    const { data, setData, post, processing, errors, transform } =
        useForm<ProductionOrderFormData>(initialProductionOrderFormData(order));

    const [landingLinkCopied, setLandingLinkCopied] = useState(false);
    const [isConfirmOpen, setIsConfirmOpen] = useState(false);
    const [isStartConfirmOpen, setIsStartConfirmOpen] = useState(false);
    const [isStartingProduction, setIsStartingProduction] = useState(false);
    const [isRejectOpen, setIsRejectOpen] = useState(false);

    usePackagingSync({
        packagingPlans: orderPackagingPlans,
        currentPackaging: data.packaging,
        setData,
    });

    useBatchUsageSync({
        sources: batchSources,
        currentRows: data.batch_consumptions,
        setData,
    });

    useProductLineUsageSync({
        lines: productLines,
        currentRows: data.product_lines,
        setData,
    });

    const updateProductActual = (index: number, value: string) =>
        setData(
            'product_lines',
            data.product_lines.map((row, rowIndex) =>
                rowIndex === index ? { ...row, actual_kg: value } : row,
            ),
        );

    const updateBatchUsage = (
        index: number,
        patch: Partial<Omit<ProductionOrderBatchUsageRow, 'id'>>,
    ) =>
        setData(
            'batch_consumptions',
            data.batch_consumptions.map((row, rowIndex) =>
                rowIndex === index ? { ...row, ...patch } : row,
            ),
        );

    const { previewCosts, previewLoading, previewStale } =
        useProductionCostPreview({
            orderId: order.id,
            ingredients: data.ingredients,
            packaging: data.packaging,
            lineAdjustments,
            remnantQuantityKg: data.remnant_quantity_kg,
            density: data.density_kg_per_gallon,
            batchUsage: data.batch_consumptions,
            sourcesKey,
            isCompleted,
            // Cancelada: sin costos (lo digitado ya no los cambia).
            enabled: can.previewCosts && !isCancelled,
        });

    // Absoluta desde el servidor: la página pública vive en el dominio del QR, no en el del ERP.
    const landingFullUrl = order.qr_landing_url ?? '';

    // Los rangos del producto, como placeholder de cada indicador de laboratorio.
    const qualityRanges = {
        viscosity: formatQualityRange(
            order.product?.quality_viscosity_lower,
            order.product?.quality_viscosity_upper,
        ),
        grinding: formatQualityRange(
            order.product?.quality_fineness_lower,
            order.product?.quality_fineness_upper,
        ),
        solids: formatQualityRange(
            order.product?.quality_solids_lower,
            order.product?.quality_solids_upper,
        ),
    };

    const {
        costSummary,
        previewProductCosts,
        productRows,
        packagingCosts,
        ingredientRows,
        packagingRows,
        packagedEquivalent,
        remnantGallons,
        realYield,
        yieldPercentage,
    } = useProductionOrderFigures({
        order,
        data,
        previewCosts,
        isPending,
        isCompleted,
        isCancelled,
        isFormReadOnly,
    });

    const showSubmit = can.submitForReview || can.complete;
    const showReject = can.rejectReview && isPendingReview;

    // «Productos a usar» solo aparece si la orden lleva algo (líneas, fuentes o el saldo que dejó). Si no, y se puede
    // planear, el botón «Agregar saldo o lote» de la sección de consumo abre el mismo selector.
    const hasProductsToUse =
        productLines.length > 0 ||
        batchSources.length > 0 ||
        (order.remnant_consumptions ?? []).length > 0 ||
        Boolean(order.remnant);
    const addProductSources =
        !hasProductsToUse && can.planRemnant
            ? {
                  remnants: order.available_remnants ?? [],
                  batches: order.available_batches ?? [],
              }
            : null;

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setIsConfirmOpen(true);
    };

    const confirmAction = () => {
        setIsConfirmOpen(false);

        const action =
            submitMode === 'review'
                ? productionOrderSubmitForReview({ production_order: order.id })
                : productionOrderComplete({ production_order: order.id });

        // Los ajustes y saldos que muestra la pantalla: el servidor rechaza si la orden cambió desde entonces (P6-2).
        transform((formData) => ({
            ...normalizeProductionOrderFormData(formData),
            line_adjustment_ids: lineAdjustments.map(
                (adjustment) => adjustment.id,
            ),
            remnant_consumption_ids: (order.remnant_consumptions ?? []).map(
                (consumption) => consumption.id,
            ),
        }));
        post(action.url, {
            preserveScroll: true,
        });
    };

    const confirmStartProduction = () => {
        setIsStartConfirmOpen(false);
        setIsStartingProduction(true);

        router.post(
            productionOrderStartProduction({ production_order: order.id }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsStartingProduction(false),
            },
        );
    };

    const handleCopyLandingLink = () => {
        if (!landingFullUrl) {
            return;
        }

        void navigator.clipboard.writeText(landingFullUrl).then(() => {
            setLandingLinkCopied(true);
            window.setTimeout(() => setLandingLinkCopied(false), 2000);
        });
    };

    return (
        <>
            <Head title={`Orden ${order.order_number}`} />
            <div className="space-y-6 p-6">
                <OrderHeader
                    order={order}
                    canUpdateColor={can.updateColor}
                    canCancel={can.cancel}
                />

                {/* En una cancelada no se produjo nada: sin cifras de rendimiento. */}
                {isReadSheet && !isCancelled && (
                    <OpKeyFigures
                        realYield={realYield}
                        yieldPercentage={yieldPercentage}
                        packagedEquivalent={packagedEquivalent}
                        remnant={order.remnant ?? null}
                        remnantKg={order.remnant_quantity_kg}
                        remnantGallons={remnantGallons}
                        costPerGallonWithCif={
                            can.previewCosts && costSummary
                                ? costSummary.cost_per_gallon_with_cif
                                : null
                        }
                    />
                )}

                {/* PC primero: las secciones en el orden del proceso y, a la derecha, el resumen y los botones. En una
                    pantalla angosta se apilan y el resumen queda al final. */}
                <form
                    noValidate
                    onSubmit={handleSubmit}
                    className="grid grid-cols-1 items-start gap-6 lg:grid-cols-3"
                >
                    <div className="min-w-0 space-y-6 lg:col-span-2">
                        {isReadSheet ? (
                            <>
                                <OpConsumptionRead
                                    orderId={order.id}
                                    data={data}
                                    setData={setData}
                                    ingredientRows={ingredientRows}
                                    productRows={productRows}
                                    lineAdjustments={lineAdjustments}
                                    isCompleted={isCompleted}
                                    showCosts={can.previewCosts}
                                    overconsumption={
                                        order.overconsumption ?? []
                                    }
                                    consumptionReasons={
                                        order.consumption_reasons ?? []
                                    }
                                    productOverconsumption={
                                        order.product_overconsumption ?? []
                                    }
                                    productConsumptionReasons={
                                        order.product_consumption_reasons ?? []
                                    }
                                />
                                <OpQualityRead
                                    viscosity={order.viscosity_ku}
                                    grinding={order.grinding_hg}
                                    solids={order.quality_solids}
                                    qualityRanges={qualityRanges}
                                />
                                <OpPackagingRead
                                    order={order}
                                    rows={packagingRows}
                                    costs={packagingCosts}
                                    labelMaterials={labelMaterials}
                                    showCosts={can.previewCosts}
                                    canPrintLabels={can.printLabels}
                                />
                                <OpClosingRead
                                    order={order}
                                    qualitySigners={qualitySigners}
                                />
                            </>
                        ) : (
                            <>
                                {hasProductsToUse && (
                                    <ProductsToUseCard
                                        order={order}
                                        showCosts={can.previewCosts}
                                        canPlan={can.planRemnant}
                                        // Lo que quedó se escribe al entregar la orden (enviar o completar).
                                        canRecordUsage={
                                            !isFormReadOnly && showSubmit
                                        }
                                        usageRows={data.batch_consumptions}
                                        lineUsageRows={data.product_lines}
                                        onUsageChange={updateBatchUsage}
                                        // Al enviar o completar: los de cada lote, el de una línea sin fuentes o un saldo que ya
                                        // no alcanza. Varias llaves no son campos del formulario.
                                        errors={
                                            errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        }
                                        previewProductCosts={
                                            previewProductCosts
                                        }
                                    />
                                )}

                                <OpConsumptionSection
                                    orderId={order.id}
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    ingredientRows={ingredientRows}
                                    lineAdjustments={lineAdjustments}
                                    rawMaterials={rawMaterials}
                                    isCompleted={isCompleted}
                                    isReadOnly={isFormReadOnly}
                                    showCosts={can.previewCosts}
                                    previewLoading={previewLoading}
                                    previewStale={previewStale}
                                    overconsumption={
                                        order.overconsumption ?? []
                                    }
                                    consumptionReasons={
                                        order.consumption_reasons ?? []
                                    }
                                    productOverconsumption={
                                        order.product_overconsumption ?? []
                                    }
                                    productConsumptionReasons={
                                        order.product_consumption_reasons ?? []
                                    }
                                    productRows={productRows}
                                    onProductActualChange={updateProductActual}
                                    addProductSources={addProductSources}
                                />

                                <OpQualitySection
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    isReadOnly={isFormReadOnly}
                                    qualityRanges={qualityRanges}
                                />

                                <OpPackagingSection
                                    orderId={order.id}
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    packagingRows={packagingRows}
                                    packagingCosts={packagingCosts}
                                    labelMaterials={labelMaterials}
                                    availableVariants={availableVariants}
                                    isReadOnly={isFormReadOnly}
                                    showCosts={can.previewCosts}
                                    canPrintLabels={can.printLabels}
                                />

                                <OpClosingSection
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    isReadOnly={isFormReadOnly}
                                />
                            </>
                        )}
                    </div>

                    <aside className="min-w-0 lg:sticky lg:top-6">
                        <OpSummaryPanel
                            order={order}
                            data={data}
                            setData={setData}
                            errors={errors}
                            packagedEquivalent={packagedEquivalent}
                            remnantKg={
                                isFormReadOnly
                                    ? order.remnant_quantity_kg
                                    : parseDecimal(data.remnant_quantity_kg)
                            }
                            remnantGallons={remnantGallons}
                            realYield={realYield}
                            yieldPercentage={yieldPercentage}
                            costSummary={costSummary}
                            showCosts={can.previewCosts}
                            isReadOnly={isFormReadOnly}
                            showStart={can.startProduction && isPending}
                            isStartingProduction={isStartingProduction}
                            onStart={() => setIsStartConfirmOpen(true)}
                            showSubmit={showSubmit}
                            submitLabel={submitLabel}
                            processing={processing}
                            processingLabel={processingLabel}
                            hasOrderData={hasOrderData}
                            showReject={showReject}
                            onReject={() => setIsRejectOpen(true)}
                            qualitySigners={qualitySigners}
                            showQualitySigner={submitMode === 'complete'}
                        >
                            {isCompleted && order.qr_landing_url && (
                                <QrLine
                                    order={order}
                                    landingLinkCopied={landingLinkCopied}
                                    onCopyLandingLink={handleCopyLandingLink}
                                />
                            )}
                        </OpSummaryPanel>
                    </aside>
                </form>

                <OpSubmitDialog
                    open={isConfirmOpen}
                    onOpenChange={setIsConfirmOpen}
                    title={confirmTitle}
                    description={confirmDescription}
                    realYield={realYield}
                    yieldPercentage={yieldPercentage}
                    processing={processing}
                    processingLabel={processingLabel}
                    onConfirm={confirmAction}
                />
                <OpStartDialog
                    open={isStartConfirmOpen}
                    onOpenChange={setIsStartConfirmOpen}
                    orderNumber={order.order_number}
                    starting={isStartingProduction}
                    onConfirm={confirmStartProduction}
                />
                <OpRejectDialog
                    orderId={order.id}
                    open={isRejectOpen}
                    onOpenChange={setIsRejectOpen}
                />
            </div>
        </>
    );
}

// Las migas son la única forma de volver al listado (DESIGN.md §10); el listado conserva sus filtros con `returnTo`.
ProductionOrderShow.layout = (props: ProductionOrderShowProps) => ({
    breadcrumbs: [
        {
            title: 'Órdenes de producción',
            href: breadcrumbHref(props.returnTo, productionOrdersIndex().url),
        },
        {
            title: props.order.order_number,
            href: productionOrdersShow({ production_order: props.order.id }),
        },
    ],
});
