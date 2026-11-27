import type {
    PreviewErrors,
    PreviewStaleReason,
} from '@/hooks/use-server-preview';
import { toDecimalPayload } from '@/lib/numeric-input';

export type MarginPreviewResponse = {
    product_sales_price: number | null;
    variants: Array<{ id: number; sales_price: number | null }>;
};

export type AnsweredPreview<TValue> = {
    value: TValue;
    context: string;
};

/**
 * Compara dos textos de margen sin usar floats («25,00» y «25» representan el mismo margen).
 */
export function normalizeMarginKey(value: string): string {
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

export type MarginPreviewState = {
    isDirty: boolean;
    /** Mapa variante → precio entero solo cuando la respuesta corresponde exactamente al `draft` vigente. */
    variantPrices: Map<number, number | null> | null;
    /** Error 422 de `sales_margin` solo cuando corresponde al `draft` vigente. */
    validationError: string | null;
    /** `true` si la última petición de vista previa falló por red/servidor/rate-limit estando sucio. */
    failed: boolean;
    /** `true` cuando los precios mostrados en la tabla corresponden exactamente al `draft` vigente. */
    isCurrent: boolean;
};

/**
 * Resuelve el estado visible de la vista previa de margen descartando respuestas o errores obsoletos de `useServerPreview`
 * (que conserva el último resultado cuando `request` vuelve a `null`, por ejemplo tras cancelar con Escape).
 */
export function resolveMarginPreviewState(params: {
    draft: string | null;
    savedMargin: string;
    previewResult: AnsweredPreview<MarginPreviewResponse> | null;
    previewErrors: AnsweredPreview<PreviewErrors> | null;
    previewStale: PreviewStaleReason;
}): MarginPreviewState {
    const { draft, savedMargin, previewResult, previewErrors, previewStale } =
        params;

    const isDirty =
        draft !== null &&
        normalizeMarginKey(draft) !== normalizeMarginKey(savedMargin);

    if (!isDirty || draft === null) {
        return {
            isDirty: false,
            variantPrices: null,
            validationError: null,
            failed: false,
            isCurrent: false,
        };
    }

    const validationError =
        previewErrors !== null && previewErrors.context === draft
            ? (previewErrors.value.sales_margin ?? null)
            : null;

    const failed = previewStale === 'failed';

    const hasCurrentResult =
        previewResult !== null &&
        previewResult.context === draft &&
        previewStale === null &&
        validationError === null;

    const variantPrices = hasCurrentResult
        ? new Map(
              previewResult.value.variants.map((variant) => [
                  variant.id,
                  variant.sales_price,
              ]),
          )
        : null;

    return {
        isDirty: true,
        variantPrices,
        validationError,
        failed,
        isCurrent: hasCurrentResult,
    };
}
