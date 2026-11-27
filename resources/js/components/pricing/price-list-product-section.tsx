import { router } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';

import { DataListing } from '@/components/data-listing';
import type { DataListingColumn } from '@/components/data-listing';
import { DecimalInput } from '@/components/decimal-input';
import { FormError } from '@/components/form-field';
import { FormattedNumber } from '@/components/formatted-number';
import { useServerPreview } from '@/hooks/use-server-preview';
import { NUMBER_TYPES } from '@/lib/number-types';
import { toDecimalPayload } from '@/lib/numeric-input';
import {
    preview as marginPreview,
    update as marginUpdate,
} from '@/routes/prices/margin';

const PREVIEW_DELAY_MS = 250;

export type PriceListVariantRow = {
    id: number;
    code: string | null;
    presentation_label: string;
    presentation_value: number | null;
    current_price?: string | number | null;
    sales_price: number | null;
    available_stock: number;
};

export type PriceListProductRow = {
    id: number;
    name: string;
    current_cost?: string | number | null;
    cif_percentage?: string | number | null;
    current_price?: string | number | null;
    sales_margin?: string | number | null;
    sales_price: number | null;
    variants: PriceListVariantRow[];
};

type PreviewRequestBody = {
    sales_margin: string | null;
};

type PreviewResponse = {
    product_sales_price: number | null;
    variants: Array<{ id: number; sales_price: number | null }>;
};

type Props = {
    product: PriceListProductRow;
    can: {
        view_costs: boolean;
        update_margin: boolean;
    };
};

/**
 * Compara dos textos de margen sin usar floats («25,00» y «25» representan el mismo margen).
 */
function normalizeMarginKey(value: string): string {
    const payload = toDecimalPayload(value).trim();

    if (payload === '') {
        return '';
    }

    if (!/^\d+(?:\.\d+)?$/.test(payload)) {
        return payload;
    }

    const [intPart, decPart = ''] = payload.split('.');
    const normalizedInt = intPart.replace(/^0+(?=\d)/, '');
    const normalizedDec = decPart.replace(/0+$/, '');

    return normalizedDec === ''
        ? normalizedInt
        : `${normalizedInt}.${normalizedDec}`;
}

export function PriceListProductSection({ product, can }: Props) {
    const savedMargin =
        product.sales_margin === null || product.sales_margin === undefined
            ? ''
            : String(product.sales_margin);

    // `null` indica que no hay edición en curso y se muestra el valor guardado del servidor.
    const [draft, setDraft] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const cancelOnBlurRef = useRef(false);

    const displayedMargin = draft ?? savedMargin;
    const isDirty =
        draft !== null &&
        normalizeMarginKey(draft) !== normalizeMarginKey(savedMargin);

    const previewRequest = useMemo(() => {
        if (!can.update_margin || !isDirty || draft === null) {
            return null;
        }

        const payload = toDecimalPayload(draft).trim();

        return {
            url: marginPreview({ product: product.id }).url,
            body: {
                sales_margin: payload === '' ? null : payload,
            },
            context: draft,
        };
    }, [can.update_margin, isDirty, draft, product.id]);

    const {
        result: previewResult,
        errors: previewErrors,
        loading: previewLoading,
    } = useServerPreview<PreviewResponse, PreviewRequestBody, string>(
        previewRequest,
        PREVIEW_DELAY_MS,
    );

    const previewVariantPrices = useMemo(() => {
        if (!isDirty || previewResult === null) {
            return null;
        }

        return new Map(
            previewResult.value.variants.map((variant) => [
                variant.id,
                variant.sales_price,
            ]),
        );
    }, [isDirty, previewResult]);

    const previewValidationError =
        isDirty && previewErrors !== null
            ? (previewErrors.value.sales_margin ?? null)
            : null;
    const activeError = saveError ?? previewValidationError;

    const displayedVariants = useMemo((): PriceListVariantRow[] => {
        if (previewVariantPrices === null) {
            return product.variants;
        }

        return product.variants.map((variant) =>
            previewVariantPrices.has(variant.id)
                ? {
                      ...variant,
                      sales_price: previewVariantPrices.get(variant.id) ?? null,
                  }
                : variant,
        );
    }, [product.variants, previewVariantPrices]);

    const handleChange = useCallback((value: string) => {
        setDraft(value);
        setSaveError(null);
    }, []);

    const handleBlur = useCallback(() => {
        if (cancelOnBlurRef.current) {
            cancelOnBlurRef.current = false;

            return;
        }

        if (!can.update_margin || draft === null) {
            return;
        }

        if (!isDirty) {
            setDraft(null);
            setSaveError(null);

            return;
        }

        const payload = toDecimalPayload(draft).trim();

        setSaveError(null);
        setSaving(true);

        router.patch(
            marginUpdate({ product: product.id }).url,
            { sales_margin: payload === '' ? null : payload },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setSaving(false);
                    setDraft(null);
                    setSaveError(null);
                },
                onError: (errorBag) => {
                    setSaving(false);

                    const serverError =
                        errorBag?.sales_margin ??
                        'Error al guardar. Intente nuevamente.';

                    setSaveError(
                        Array.isArray(serverError)
                            ? serverError[0]
                            : serverError,
                    );
                },
            },
        );
    }, [can.update_margin, draft, isDirty, product.id]);

    const handleKeyDown = useCallback((e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            e.currentTarget.blur();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelOnBlurRef.current = true;
            setDraft(null);
            setSaveError(null);
            e.currentTarget.blur();
        }
    }, []);

    const variantColumns: DataListingColumn<PriceListVariantRow>[] = useMemo(
        () => [
            {
                key: 'presentation',
                header: 'Presentación',
                cell: (variant) => variant.presentation_label,
                mobile: 'title',
            },
            {
                key: 'code',
                header: 'Código',
                cell: (variant) => variant.code,
                mobile: 'subtitle',
                className: 'font-mono text-muted-foreground',
                cardClassName: 'font-mono',
            },
            ...(can.view_costs
                ? [
                      {
                          // Costo con CIF: no es el «Costo» de la línea del producto.
                          key: 'internal_price',
                          header: 'Precio interno',
                          // Sin precio es un dato que falta (aún sin costear), como el de venta.
                          cell: (variant: PriceListVariantRow) => (
                              <FormattedNumber
                                  value={variant.current_price}
                                  kind="cost"
                                  emptyValue="Sin precio"
                              />
                          ),
                          mobile: 'meta' as const,
                          align: 'right' as const,
                          className: 'text-muted-foreground',
                      },
                  ]
                : []),
            {
                key: 'sales_price',
                header: 'Precio de venta',
                // Sin precio es un dato que falta, no un vacío: se dice en vez de omitirlo.
                cell: (variant) => (
                    <FormattedNumber
                        value={variant.sales_price}
                        kind="clientPrice"
                        emptyValue="Sin precio"
                        className={
                            previewLoading && isDirty ? 'opacity-60' : undefined
                        }
                    />
                ),
                mobile: 'primary',
                align: 'right',
                className: 'font-medium',
            },
            {
                key: 'stock',
                header: 'Stock disponible',
                cell: (variant) => (
                    <FormattedNumber
                        value={variant.available_stock}
                        kind="units"
                    />
                ),
                mobile: 'meta',
                align: 'right',
            },
        ],
        [can.view_costs, previewLoading, isDirty],
    );

    return (
        <section className="space-y-3">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">{product.name}</h2>

                {can.view_costs && (
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                            <span className="whitespace-nowrap">
                                Costo:{' '}
                                <FormattedNumber
                                    value={product.current_cost}
                                    kind="cost"
                                />
                            </span>
                            <span
                                aria-hidden="true"
                                className="hidden sm:inline"
                            >
                                ·
                            </span>
                            <span className="whitespace-nowrap">
                                CIF:{' '}
                                <FormattedNumber
                                    value={product.cif_percentage}
                                    kind="percent"
                                />
                            </span>
                            <span
                                aria-hidden="true"
                                className="hidden sm:inline"
                            >
                                ·
                            </span>

                            {can.update_margin ? (
                                <div className="inline-flex flex-wrap items-center gap-1.5">
                                    <span className="whitespace-nowrap">
                                        Margen de venta:
                                    </span>
                                    <DecimalInput
                                        {...NUMBER_TYPES.percentage}
                                        value={displayedMargin}
                                        onValueChange={handleChange}
                                        onBlur={handleBlur}
                                        onKeyDown={handleKeyDown}
                                        disabled={saving}
                                        aria-label={`Margen de venta de ${product.name}`}
                                        aria-invalid={activeError !== null}
                                        className="w-20 text-right"
                                    />
                                    <span className="whitespace-nowrap">%</span>
                                    {saving && (
                                        <span className="animate-pulse text-xs text-muted-foreground">
                                            Guardando...
                                        </span>
                                    )}
                                    {!saving && previewLoading && isDirty && (
                                        <span className="animate-pulse text-xs text-muted-foreground">
                                            Calculando...
                                        </span>
                                    )}
                                </div>
                            ) : (
                                <span className="whitespace-nowrap">
                                    Margen de venta:{' '}
                                    <FormattedNumber
                                        value={product.sales_margin}
                                        kind="percent"
                                    />
                                </span>
                            )}
                        </div>

                        {activeError && <FormError message={activeError} />}
                    </div>
                )}
            </div>

            <DataListing
                rows={displayedVariants}
                rowKey={(variant) => variant.id}
                columns={variantColumns}
                empty="No hay presentaciones registradas."
            />
        </section>
    );
}
