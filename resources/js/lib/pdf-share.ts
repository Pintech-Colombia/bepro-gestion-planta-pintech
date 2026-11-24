export type PdfDeliveryOutcome =
    'share' | 'download' | 'needs_gesture' | 'aborted' | 'share_stuck';

export type PdfResponseValidation =
    | { valid: true }
    | {
          valid: false;
          reason:
              | 'session_expired'
              | 'redirected'
              | 'unavailable'
              | 'http_error'
              | 'invalid_content_type';
          requiresReload: boolean;
      };

type PdfResponseMeta = {
    ok: boolean;
    redirected: boolean;
    status: number;
    contentType: string | null;
};

type ShareNavigator = Pick<Partial<Navigator>, 'share' | 'canShare'>;

/** Título corto para la hoja de compartir del sistema. */
export function quotationPdfTitle(quotation: {
    id: number;
    quotation_number: number | null;
}): string {
    return `Cotización ${quotation.quotation_number ?? quotation.id}`;
}

export function isPdfContentType(contentType: string | null): boolean {
    if (!contentType) {
        return false;
    }

    return contentType.toLowerCase().split(';')[0].trim() === 'application/pdf';
}

/**
 * Comprueba que el `fetch` devolvió el PDF real y no una redirección silenciosa al inicio de sesión (302 seguido por
 * `fetch` -> 200 con HTML) ni un error del servidor.
 */
export function validatePdfResponse(
    response: PdfResponseMeta,
): PdfResponseValidation {
    if (response.status === 401 || response.status === 419) {
        return {
            valid: false,
            reason: 'session_expired',
            requiresReload: true,
        };
    }

    // `fetch` siguió una redirección: al inicio de sesión, a verificar el correo o a otra página. No se sabe cuál;
    // recargar deja que el sistema lleve a donde corresponda.
    if (response.redirected) {
        return {
            valid: false,
            reason: 'redirected',
            requiresReload: true,
        };
    }

    // Sin permiso o la cotización ya no existe: reintentar no lo arregla.
    if (response.status === 403 || response.status === 404) {
        return {
            valid: false,
            reason: 'unavailable',
            requiresReload: false,
        };
    }

    if (!response.ok) {
        return {
            valid: false,
            reason: 'http_error',
            requiresReload: false,
        };
    }

    if (!isPdfContentType(response.contentType)) {
        return {
            valid: false,
            reason: 'invalid_content_type',
            requiresReload: true,
        };
    }

    return { valid: true };
}

function errorName(error: unknown): string | null {
    if (typeof error !== 'object' || error === null || !('name' in error)) {
        return null;
    }

    return typeof error.name === 'string' ? error.name : null;
}

/**
 * Decide si se comparte el archivo, se descarga, se pide un segundo toque (gesto vencido en Safari de iOS) o se
 * descarta en silencio (el usuario cerró la hoja de compartir).
 */
export function resolvePdfShareAction(options: {
    canShare: boolean;
    error?: unknown;
    fromReadyFile?: boolean;
}): PdfDeliveryOutcome {
    if (!options.canShare) {
        return 'download';
    }

    if (options.error === undefined) {
        return 'share';
    }

    const name = errorName(options.error);

    if (name === 'AbortError') {
        return 'aborted';
    }

    // Otra hoja de compartir sigue «abierta» para el navegador: en algunos Android nunca avisa que se cerró y cada
    // intento siguiente falla así hasta recargar. Descargar en silencio en cada toque confunde: se avisa.
    if (name === 'InvalidStateError') {
        return 'share_stuck';
    }

    if (name === 'NotAllowedError' && !options.fromReadyFile) {
        return 'needs_gesture';
    }

    return 'download';
}

/** Si el navegador puede compartir estos archivos con la hoja de compartir del sistema. Una sola comprobación. */
export function canShareFiles(
    files: File[],
    nav: ShareNavigator | undefined = typeof navigator !== 'undefined'
        ? navigator
        : undefined,
): boolean {
    if (
        !nav ||
        typeof nav.share !== 'function' ||
        typeof nav.canShare !== 'function'
    ) {
        return false;
    }

    try {
        return nav.canShare({ files });
    } catch {
        return false;
    }
}

/** Comprueba si el navegador soporta compartir un archivo PDF sin descargarlo antes. */
export function canBrowserSharePdf(
    nav: ShareNavigator | undefined = typeof navigator !== 'undefined'
        ? navigator
        : undefined,
): boolean {
    if (typeof File === 'undefined') {
        return false;
    }

    return canShareFiles(
        [new File([''], 'probe.pdf', { type: 'application/pdf' })],
        nav,
    );
}
