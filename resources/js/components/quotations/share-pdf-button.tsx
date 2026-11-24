import { FileText } from 'lucide-react';
import type { ComponentProps } from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import { RetryableError } from '@/components/retryable-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    canBrowserSharePdf,
    canShareFiles,
    quotationPdfTitle,
    resolvePdfShareAction,
    validatePdfResponse,
} from '@/lib/pdf-share';
import type { PdfResponseValidation } from '@/lib/pdf-share';
import { exportPdf as quotationsExportPdf } from '@/routes/quotations';

export type SharePdfErrorState = {
    message: string;
    /** Sin `retry`, reintentar no lo arreglaría (sin permiso, la cotización ya no existe): solo se muestra el aviso. */
    retry?: () => void;
};

type PdfButtonProps = {
    quotationId: number;
    quotationNumber: number | null;
    /** El nombre del archivo, tal como lo arma el servidor (`Quotation::pdfFilename()`). */
    filename: string;
    variant?: ComponentProps<typeof Button>['variant'];
    className?: string;
    /**
     * Si la pantalla pasa este callback, pinta `<RetryableError>` donde le convenga (bajo `<PageHeader>` o en la barra
     * fija); si no, este componente lo muestra debajo del botón.
     */
    onErrorChange?: (error: SharePdfErrorState | null) => void;
};

const subscribeNoop = () => () => {};

let cachedCanSharePdf: boolean | undefined;

function getCanSharePdfSnapshot(): boolean {
    if (cachedCanSharePdf === undefined) {
        cachedCanSharePdf = canBrowserSharePdf();
    }

    return cachedCanSharePdf;
}

function getServerSnapshot(): boolean {
    return false;
}

/** Indica si el navegador actual puede compartir archivos PDF (siempre `false` en SSR). */
export function useCanSharePdf(): boolean {
    return useSyncExternalStore(
        subscribeNoop,
        getCanSharePdfSnapshot,
        getServerSnapshot,
    );
}

function triggerPdfDownload(file: File): void {
    const objectUrl = URL.createObjectURL(file);
    const link = document.createElement('a');

    link.href = objectUrl;
    link.download = file.name;
    document.body.appendChild(link);
    link.click();
    link.remove();

    // Revocar de inmediato puede cancelar la descarga en Safari.
    window.setTimeout(() => {
        URL.revokeObjectURL(objectUrl);
    }, 60_000);
}

type PdfFetchFailure =
    Extract<PdfResponseValidation, { valid: false }>['reason'] | 'network';

/** El mensaje de cada falla al pedir el PDF, en un solo lugar para «Compartir» y «Ver». */
function pdfFailureMessage(reason: PdfFetchFailure): string {
    switch (reason) {
        case 'session_expired':
            return 'Tu sesión venció. Recarga la página e inicia sesión otra vez.';
        case 'redirected':
        case 'invalid_content_type':
            return 'No se pudo obtener el PDF. Recarga la página para volver a intentarlo.';
        case 'unavailable':
            return 'No tienes acceso a esta cotización o ya no existe.';
        case 'http_error':
            return 'No se pudo preparar el PDF de la cotización. Inténtalo de nuevo.';
        case 'network':
            return 'No se pudo descargar el PDF. Revisa tu conexión e inténtalo de nuevo.';
    }
}

type PdfFetchResult =
    | { ok: true; file: File }
    | { ok: false; reason: PdfFetchFailure; requiresReload: boolean }
    | { ok: false; reason: 'aborted' };

/**
 * Pide el PDF fresco al servidor (`?download=1`, sin caché) y comprueba que lo que llegó sea de verdad un PDF: con la
 * sesión vencida, `fetch` sigue en silencio la redirección al login y devolvería su HTML.
 */
async function fetchQuotationPdf(
    quotationId: number,
    filename: string,
    signal: AbortSignal,
): Promise<PdfFetchResult> {
    try {
        const response = await fetch(
            quotationsExportPdf(quotationId, { query: { download: 1 } }).url,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal,
                headers: { Accept: 'application/pdf' },
            },
        );

        const validation = validatePdfResponse({
            ok: response.ok,
            redirected: response.redirected,
            status: response.status,
            contentType: response.headers.get('content-type'),
        });

        if (!validation.valid) {
            return {
                ok: false,
                reason: validation.reason,
                requiresReload: validation.requiresReload,
            };
        }

        const blob = await response.blob();

        return {
            ok: true,
            file: new File([blob], filename, { type: 'application/pdf' }),
        };
    } catch (error) {
        if (
            signal.aborted ||
            (error instanceof DOMException && error.name === 'AbortError')
        ) {
            return { ok: false, reason: 'aborted' };
        }

        return { ok: false, reason: 'network', requiresReload: false };
    }
}

/** El aviso de una falla: recargar, reintentar o, si no tiene arreglo (sin acceso), solo el mensaje. */
function failureState(
    reason: PdfFetchFailure,
    requiresReload: boolean,
    retry: () => void,
): SharePdfErrorState {
    return {
        message: pdfFailureMessage(reason),
        retry:
            reason === 'unavailable'
                ? undefined
                : requiresReload
                  ? () => window.location.reload()
                  : retry,
    };
}

function useReportError(
    onErrorChange: PdfButtonProps['onErrorChange'],
): [SharePdfErrorState | null, (error: SharePdfErrorState | null) => void] {
    const [localError, setLocalError] = useState<SharePdfErrorState | null>(
        null,
    );

    const report = (error: SharePdfErrorState | null) => {
        if (onErrorChange) {
            onErrorChange(error);
        } else {
            setLocalError(error);
        }
    };

    return [localError, report];
}

function useAbortOnUnmount() {
    const abortControllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        return () => {
            abortControllerRef.current?.abort();
        };
    }, []);

    const start = () => {
        abortControllerRef.current?.abort();
        const controller = new AbortController();
        abortControllerRef.current = controller;

        return controller;
    };

    const isCurrent = (controller: AbortController) =>
        abortControllerRef.current === controller;

    return { start, isCurrent };
}

function withLocalError(
    button: React.ReactNode,
    localError: SharePdfErrorState | null,
    hasCallback: boolean,
) {
    if (hasCallback || !localError) {
        return button;
    }

    return (
        <div className="space-y-3">
            {button}
            <RetryableError
                message={localError.message}
                onRetry={localError.retry}
            />
        </div>
    );
}

/**
 * Descarga el PDF de la cotización y lo entrega como archivo a través de la hoja de compartir del dispositivo
 * (WhatsApp, correo, etc.), sin exponer la URL del sistema. Si el navegador no soporta compartir archivos, descarga el
 * PDF. En Safari de iOS, si la descarga agota el gesto táctil (`NotAllowedError`), deja el archivo listo y cambia la
 * etiqueta a «Compartir ahora» para que el segundo toque abra la hoja al instante.
 */
export function SharePdfButton({
    quotationId,
    quotationNumber,
    filename,
    variant = 'default',
    className,
    onErrorChange,
}: PdfButtonProps) {
    const [isPreparing, setIsPreparing] = useState(false);
    const [readyFile, setReadyFile] = useState<File | null>(null);
    const [localError, reportError] = useReportError(onErrorChange);
    const { start, isCurrent } = useAbortOnUnmount();

    const shareTitle = quotationPdfTitle({
        id: quotationId,
        quotation_number: quotationNumber,
    });

    const share = async (file: File, fromReadyFile: boolean): Promise<void> => {
        try {
            await navigator.share({ files: [file], title: shareTitle });
            setReadyFile(null);
        } catch (error) {
            const outcome = resolvePdfShareAction({
                canShare: true,
                error,
                fromReadyFile,
            });

            if (outcome === 'needs_gesture') {
                setReadyFile(file);

                return;
            }

            setReadyFile(null);

            if (outcome === 'share_stuck') {
                reportError({
                    message:
                        'El teléfono no volvió a abrir el menú de compartir. Recarga la página e intenta de nuevo.',
                    retry: () => window.location.reload(),
                });

                return;
            }

            if (outcome === 'download') {
                triggerPdfDownload(file);
            }
        }
    };

    const fetchAndSharePdf = async (): Promise<void> => {
        reportError(null);
        setIsPreparing(true);
        const controller = start();

        const result = await fetchQuotationPdf(
            quotationId,
            filename,
            controller.signal,
        );

        // El «Preparando…» termina en cuanto el archivo está listo (o falló), antes de abrir la hoja de compartir: en
        // algunos Android esa hoja nunca avisa que se cerró y el botón quedaría bloqueado.
        if (isCurrent(controller)) {
            setIsPreparing(false);
        }

        if (!result.ok) {
            if (result.reason !== 'aborted') {
                reportError(
                    failureState(result.reason, result.requiresReload, () => {
                        void fetchAndSharePdf();
                    }),
                );
            }

            return;
        }

        const initialAction = resolvePdfShareAction({
            canShare: canShareFiles([result.file]),
        });

        if (initialAction === 'download') {
            triggerPdfDownload(result.file);

            return;
        }

        await share(result.file, false);
    };

    const handleClick = () => {
        if (readyFile) {
            reportError(null);
            // Sin `await` previo: conserva el gesto táctil en Safari de iOS.
            void share(readyFile, true);

            return;
        }

        void fetchAndSharePdf();
    };

    const label = isPreparing
        ? 'Preparando PDF…'
        : readyFile
          ? 'Compartir ahora'
          : 'Compartir PDF';

    return withLocalError(
        <Button
            type="button"
            variant={variant}
            className={className}
            disabled={isPreparing}
            onClick={handleClick}
        >
            {isPreparing ? <Spinner /> : <FileText className="size-4" />}
            {label}
        </Button>,
        localError,
        Boolean(onErrorChange),
    );
}

/** Si el navegador muestra PDF por sí mismo (Safari de iOS sí; Chrome de Android no: los descarga siempre). */
function browserShowsPdf(): boolean {
    return (
        typeof navigator !== 'undefined' && navigator.pdfViewerEnabled === true
    );
}

/**
 * «Ver PDF» en el celular: pide el PDF fresco (sin caché). Si el navegador sabe mostrar PDF (iPhone), lo abre en una
 * pestaña nueva como documento temporal (`blob:`): se ve sin descargarlo y esa pestaña no tiene un enlace que sirva a
 * otra persona. La pestaña se abre en el mismo toque (si no, Safari la bloquea) y se llena cuando llega el PDF. Si no
 * sabe mostrarlo (Chrome de Android, que descarga todo PDF) o no deja abrir la pestaña, lo descarga con su nombre
 * («COT. N.pdf») y el teléfono lo abre en su visor.
 */
export function ViewPdfButton({
    quotationId,
    filename,
    variant = 'outline',
    className,
    onErrorChange,
}: Omit<PdfButtonProps, 'quotationNumber'>) {
    const [isPreparing, setIsPreparing] = useState(false);
    const [localError, reportError] = useReportError(onErrorChange);
    const { start, isCurrent } = useAbortOnUnmount();

    const openPdf = async (): Promise<void> => {
        reportError(null);
        setIsPreparing(true);
        const controller = start();
        // En el mismo toque: abrirla después del `await` la dejaría bloqueada como ventana emergente.
        const tab = browserShowsPdf() ? window.open('', '_blank') : null;

        const result = await fetchQuotationPdf(
            quotationId,
            filename,
            controller.signal,
        );

        if (isCurrent(controller)) {
            setIsPreparing(false);
        }

        if (!result.ok) {
            tab?.close();

            if (result.reason !== 'aborted') {
                reportError(
                    failureState(result.reason, result.requiresReload, () => {
                        void openPdf();
                    }),
                );
            }

            return;
        }

        if (!tab) {
            triggerPdfDownload(result.file);

            return;
        }

        // No se libera con un temporizador: si el teléfono recarga esa pestaña más tarde, quedaría en blanco. El navegador
        // lo libera al cerrar o recargar la página que lo creó.
        tab.location.href = URL.createObjectURL(result.file);
    };

    return withLocalError(
        <Button
            type="button"
            variant={variant}
            className={className}
            disabled={isPreparing}
            onClick={() => void openPdf()}
        >
            {isPreparing ? <Spinner /> : <FileText className="size-4" />}
            {isPreparing ? 'Abriendo PDF…' : 'Ver PDF'}
        </Button>,
        localError,
        Boolean(onErrorChange),
    );
}
