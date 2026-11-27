import { Head, router, useForm } from '@inertiajs/react';

import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import {
    complete as productionOrderComplete,
    rejectReview as productionOrderRejectReview,
    startProduction as productionOrderStartProduction,
    submitForReview as productionOrderSubmitForReview,
} from '@/actions/App/Http/Controllers/ProductionOrderController';
import type { ProductTableRow } from '@/components/production/ingredients-table';
import { OpClosingRead } from '@/components/production/op-closing-read';
import { OpClosingSection } from '@/components/production/op-closing-section';
import { OpConsumptionRead } from '@/components/production/op-consumption-read';
import { OpConsumptionSection } from '@/components/production/op-consumption-section';
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
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    mapBatchSourceToUsageRow,
    mapProductLineToUsageRow,
    useBatchUsageSync,
    useProductLineUsageSync,
} from '@/hooks/use-batch-usage-sync';
import {
    mapPackagingPlanToFormRow,
    usePackagingSync,
} from '@/hooks/use-packaging-sync';
import { useProductionCostPreview } from '@/hooks/use-production-cost-preview';
import {
    formatForInput,
    formatPercent,
    formatQualityRange,
    formatQuantity,
} from '@/lib/formatters';
import { breadcrumbHref } from '@/lib/navigation';
import { parseDecimal, toDecimalPayload } from '@/lib/numeric-input';
import { remnantGallonsForDisplay } from '@/lib/remnant-quantity';
import {
    index as productionOrdersIndex,
    show as productionOrdersShow,
} from '@/routes/production-orders';
import type {
    PreviewCostData,
    ProductionOrderBatchUsageRow,
    ProductionOrderDetail,
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
        useForm<ProductionOrderFormData>({
            viscosity_ku: order.viscosity_ku ?? '',
            grinding_hg: order.grinding_hg ?? '',
            quality_solids: order.quality_solids ?? '',
            agitation_start_time: order.agitation_start_time ?? '',
            agitation_end_time: order.agitation_end_time ?? '',
            packaging_start_time: order.packaging_start_time ?? '',
            packaging_end_time: order.packaging_end_time ?? '',
            responsible_name: order.responsible_name ?? '',
            quality_responsible_user_id:
                order.quality_responsible_user_id ?? null,
            spillage_quantity: order.spillage_quantity ?? 0,
            density_kg_per_gallon: order.density_kg_per_gallon ?? '',
            // Lo que la planta informó al enviar a revisión: quien aprueba lo ve y lo confirma.
            remnant_quantity_kg: formatForInput(order.remnant_quantity_kg),
            remnant_notes: order.remnant_notes ?? '',
            notes: order.notes ?? '',
            ingredients: orderDetails.map(mapDetailToIngredientFormRow),
            packaging: orderPackagingPlans.map((pack) =>
                mapPackagingPlanToFormRow(pack),
            ),
            // Los motivos guardados (si la orden volvió de revisión, se editan); el servidor dice cuáles faltan.
            overconsumption_reasons: Object.fromEntries(
                (order.consumption_reasons ?? []).map((reason) => [
                    String(reason.raw_material_id),
                    reason.reason,
                ]),
            ),
            product_overconsumption_reasons: Object.fromEntries(
                (order.product_consumption_reasons ?? []).map((reason) => [
                    String(reason.product_id),
                    reason.reason,
                ]),
            ),
            // Por lote de PT usado: lo que la planta registró al enviar a revisión (quien aprueba lo ve y lo corrige).
            batch_consumptions: batchSources.map((source) =>
                mapBatchSourceToUsageRow(source),
            ),
            // Por producto usado: lo gastado que la planta registró al enviar a revisión.
            product_lines: productLines.map((line) =>
                mapProductLineToUsageRow(line),
            ),
        });

    const {
        data: rejectData,
        setData: setRejectData,
        post: postReject,
        processing: rejectProcessing,
        errors: rejectErrors,
        reset: resetRejectForm,
    } = useForm({ reason: '' });
    // La orden cambió de estado mientras se escribía el motivo (RejectsStaleOrderWrites): el diálogo sigue abierto.
    const rejectStaleError = (rejectErrors as Partial<Record<string, string>>)
        .production_order;

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

    const previewIngredientsById = useMemo(() => {
        if (!previewCosts) {
            return new Map<number, PreviewCostData['ingredients'][number]>();
        }

        return new Map(
            previewCosts.ingredients.map((ingredient) => [
                ingredient.id,
                ingredient,
            ]),
        );
    }, [previewCosts]);

    // Una sola cuenta, la del servidor: la vista previa mientras se digita y, si aún no llega (o la orden está
    // completada), el resumen que trajo la página. Cancelada: null.
    const costSummary =
        isCompleted || isCancelled
            ? (order.cost_summary ?? null)
            : (previewCosts?.summary ?? order.cost_summary ?? null);

    // El costo de cada lote con lo que quedó tal como se escribe; completada o cancelada, el de la página.
    const previewProductCosts = useMemo(
        () =>
            isCompleted || isCancelled || !previewCosts
                ? null
                : new Map(
                      (previewCosts.products ?? []).map((product) => [
                          product.id,
                          product.cost,
                      ]),
                  ),
        [isCompleted, isCancelled, previewCosts],
    );

    // El costo de cada producto (renglón de la tabla) con lo que quedó tal como se escribe; si no, el de la página.
    const previewProductLineCosts = useMemo(
        () =>
            isCompleted || isCancelled || !previewCosts
                ? null
                : new Map(
                      (previewCosts.product_lines ?? []).map((line) => [
                          line.id,
                          line,
                      ]),
                  ),
        [isCompleted, isCancelled, previewCosts],
    );

    // Cada producto como un renglón más de la tabla de consumo, en su paso; de dónde sale está en «Productos a usar».
    const productRows: ProductTableRow[] = productLines.map((line) => {
        const index = data.product_lines.findIndex((row) => row.id === line.id);
        const previewLine = previewProductLineCosts?.get(line.id);

        return {
            line,
            index,
            actualKg:
                isFormReadOnly || index < 0
                    ? formatForInput(line.actual_kg)
                    : data.product_lines[index].actual_kg,
            unitCost: previewLine ? previewLine.unit_cost : line.unit_cost,
            totalCost: previewLine ? previewLine.cost : line.cost,
        };
    });

    const packagingCosts = useMemo(
        () =>
            new Map(
                (costSummary?.packaging ?? []).map((pack) => [pack.id, pack]),
            ),
        [costSummary],
    );

    const ingredientRows = isFormReadOnly
        ? orderDetails.map(mapDetailToIngredientFormRow)
        : data.ingredients.map((ingredient) => ({
              ...ingredient,
              unit_cost:
                  previewIngredientsById.get(ingredient.id)?.unit_cost ??
                  ingredient.unit_cost ??
                  0,
              total_cost:
                  previewIngredientsById.get(ingredient.id)?.total_cost ??
                  ingredient.total_cost ??
                  0,
          }));

    const packagingRows = isFormReadOnly
        ? orderPackagingPlans.map((pack) => mapPackagingPlanToFormRow(pack))
        : data.packaging;

    // Solo para mostrar: el rendimiento real que se guarda lo calcula el servidor (lo envasado más el sobrante). Con la
    // orden cerrada a la edición se muestra el guardado. Lo digitado se lee con parseDecimal, como en el resto de los
    // formularios: llega como texto y con coma («2,5»). Pendiente, nada se ha producido: las filas traen lo planeado.
    const packagedEquivalent = isPending
        ? null
        : packagingRows.reduce(
              (sum, pack) =>
                  sum +
                  (parseDecimal(pack.actual_units) ?? 0) *
                      (parseDecimal(pack.presentation_value) ?? 0),
              0,
          );
    const remnantGallons = isFormReadOnly
        ? (parseDecimal(order.remnant_quantity_gallons) ?? 0)
        : (remnantGallonsForDisplay(
              data.remnant_quantity_kg,
              data.density_kg_per_gallon,
          ) ?? 0);
    const realYield =
        packagedEquivalent === null
            ? null
            : isFormReadOnly && order.actual_quantity != null
              ? (parseDecimal(order.actual_quantity) ?? 0)
              : packagedEquivalent + remnantGallons;
    const plannedQuantity = parseDecimal(order.quantity) ?? 0;
    const yieldPercentage =
        realYield !== null && plannedQuantity > 0
            ? (realYield / plannedQuantity) * 100
            : null;

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
            product_lines: formData.product_lines.map((row) => ({
                id: row.id,
                actual_kg: toDecimalPayload(row.actual_kg),
            })),
            // Lo que quedó solo viaja con «sí»; con «no», nada (el servidor lo toma como que no quedó producto).
            batch_consumptions: formData.batch_consumptions.map((row) => ({
                id: row.id,
                has_leftover: row.has_leftover,
                leftover_kg: row.has_leftover
                    ? toDecimalPayload(row.leftover_kg)
                    : null,
            })),
        }));
        post(action.url, {
            preserveScroll: true,
        });
    };

    const confirmReject = () => {
        postReject(
            productionOrderRejectReview({ production_order: order.id }).url,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsRejectOpen(false);
                    resetRejectForm();
                },
            },
        );
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

                <AlertDialog
                    open={isConfirmOpen}
                    onOpenChange={setIsConfirmOpen}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{confirmTitle}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {confirmDescription} Rendimiento real:{' '}
                                {formatQuantity(realYield, {
                                    maxDecimals: 2,
                                    trimTrailingZeros: true,
                                })}{' '}
                                gal
                                {yieldPercentage !== null &&
                                    ` (${formatPercent(yieldPercentage, 1)} de lo proyectado)`}
                                .
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={processing}>
                                Cancelar
                            </AlertDialogCancel>
                            <AlertDialogAction
                                onClick={confirmAction}
                                disabled={processing}
                            >
                                {processing ? processingLabel : 'Confirmar'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <AlertDialog
                    open={isStartConfirmOpen}
                    onOpenChange={setIsStartConfirmOpen}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                ¿Iniciar producción?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                La orden {order.order_number} pasará a estado En
                                proceso. Podrás registrar datos de planta y
                                enviarla a revisión cuando esté lista.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={isStartingProduction}>
                                Cancelar
                            </AlertDialogCancel>
                            <AlertDialogAction
                                onClick={confirmStartProduction}
                                disabled={isStartingProduction}
                            >
                                {isStartingProduction
                                    ? 'Iniciando...'
                                    : 'Iniciar producción'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <AlertDialog open={isRejectOpen} onOpenChange={setIsRejectOpen}>
                    <AlertDialogContent>
                        <form
                            noValidate
                            onSubmit={(e) => {
                                e.preventDefault();
                                confirmReject();
                            }}
                        >
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    ¿Devolver orden a planta?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    Indica el motivo del rechazo para que el
                                    operador pueda corregirlo.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <div className="py-4">
                                <Label htmlFor="reject-reason">
                                    Motivo del rechazo
                                </Label>
                                <Textarea
                                    id="reject-reason"
                                    maxLength={2000}
                                    className="mt-2"
                                    placeholder="Describe qué debe corregirse..."
                                    value={rejectData.reason}
                                    onChange={(e) =>
                                        setRejectData('reason', e.target.value)
                                    }
                                />
                                {rejectErrors.reason && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {rejectErrors.reason}
                                    </p>
                                )}
                                {rejectStaleError && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {rejectStaleError}
                                    </p>
                                )}
                            </div>
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={rejectProcessing}>
                                    Cancelar
                                </AlertDialogCancel>
                                <AlertDialogAction
                                    disabled={rejectProcessing}
                                    onClick={(event) => {
                                        // Sin esto Radix cierra el diálogo al hacer clic, antes de que responda el
                                        // servidor, y un error del motivo queda oculto hasta reabrirlo.
                                        event.preventDefault();
                                        confirmReject();
                                    }}
                                >
                                    {rejectProcessing
                                        ? 'Devolviendo...'
                                        : 'Devolver a planta'}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </form>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </>
    );
}

function mapDetailToIngredientFormRow(detail: ProductionOrderDetail) {
    return {
        id: detail.id,
        raw_material_id: detail.raw_material_id,
        step_order: detail.step_order ?? null,
        raw_material_name: detail.raw_material?.code,
        raw_material_unit: detail.raw_material?.unit_symbol,
        planned_quantity: detail.planned_quantity,
        display_quantity: detail.display_quantity ?? null,
        display_unit: detail.display_unit ?? null,
        // Lo real en la unidad en que se digita o, si aún no hay, lo planeado. Llegan como texto con 4 decimales.
        actual_quantity: formatForInput(
            detail.actual_display_quantity ??
                detail.display_quantity ??
                detail.planned_quantity,
        ),
        unit_cost: detail.unit_cost ?? 0,
        total_cost: detail.total_cost ?? 0,
    };
}

function normalizeProductionOrderFormData(data: ProductionOrderFormData) {
    return {
        ...data,
        viscosity_ku: toDecimalPayload(data.viscosity_ku),
        grinding_hg: toDecimalPayload(data.grinding_hg),
        quality_solids: toDecimalPayload(data.quality_solids),
        spillage_quantity: toDecimalPayload(data.spillage_quantity),
        density_kg_per_gallon: toDecimalPayload(data.density_kg_per_gallon),
        remnant_quantity_kg: toDecimalPayload(data.remnant_quantity_kg),
        ingredients: data.ingredients.map((ingredient) => ({
            id: ingredient.id,
            actual_quantity: toDecimalPayload(ingredient.actual_quantity),
        })),
        packaging: data.packaging.map((pack) => ({
            id: pack.id,
            actual_units: pack.actual_units,
            // Vacío = tantos como unidades envasadas (lo resuelve el servidor).
            new_containers_used:
                pack.new_containers_used === ''
                    ? null
                    : pack.new_containers_used,
            label_raw_material_id: pack.label_raw_material_id,
            labels_used: pack.labels_used === '' ? null : pack.labels_used,
        })),
    };
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
