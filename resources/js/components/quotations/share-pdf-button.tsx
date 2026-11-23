import { FileText } from 'lucide-react';
import type { ComponentProps } from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import { RetryableError } from '@/components/retryable-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    canBrowserSharePdf,
    quotationPdfFilename,
    quotationPdfTitle,
    resolvePdfShareAction,
    validatePdfResponse,
} from '@/lib/pdf-share';
import { exportPdf as quotationsExportPdf } from '@/routes/quotations';

export type SharePdfErrorState = {
    message: string;
    retry: () => void;
};

type SharePdfButtonProps = {
    quotationId: number;
    quotationNumber: number | null;
    variant?: ComponentProps<typeof Button>['variant'];
    className?: string;
    /**
     * Si la pantalla pasa este callback, pinta `<RetryableError>` fuera de la barra de botones (p. ej. bajo
     * `<PageHeader>`); si no, este componente lo muestra debajo del botón.
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

function canShareFile(file: File): boolean {
    if (
        typeof navigator === 'undefined' ||
        typeof navigator.share !== 'function' ||
        typeof navigator.canShare !== 'function'
    ) {
        return false;
    }

    try {
        return navigator.canShare({ files: [file] });
    } catch {
        return false;
    }
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
    variant = 'default',
    className,
    onErrorChange,
}: SharePdfButtonProps) {
    const [isPreparing, setIsPreparing] = useState(false);
    const [readyFile, setReadyFile] = useState<File | null>(null);
    const [localError, setLocalError] = useState<SharePdfErrorState | null>(
        null,
    );
    const abortControllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        return () => {
            abortControllerRef.current?.abort();
        };
    }, []);

    const reportError = (error: SharePdfErrorState | null) => {
        if (onErrorChange) {
            onErrorChange(error);
        } else {
            setLocalError(error);
        }
    };

    const shareTitle = quotationPdfTitle({
        id: quotationId,
        quotation_number: quotationNumber,
    });
    const pdfFilename = quotationPdfFilename({
        id: quotationId,
        quotation_number: quotationNumber,
    });

    const sharePreparedFile = async (file: File): Promise<void> => {
        reportError(null);

        try {
            // Se llama sin `await` previo para conservar el gesto táctil en Safari de iOS.
            await navigator.share({
                files: [file],
                title: shareTitle,
            });
            setReadyFile(null);
        } catch (error) {
            const outcome = resolvePdfShareAction({
                canShare: true,
                error,
                fromReadyFile: true,
            });

            setReadyFile(null);

            if (outcome === 'download') {
                triggerPdfDownload(file);
            }
        }
    };

    const fetchAndSharePdf = async (): Promise<void> => {
        reportError(null);
        setIsPreparing(true);

        abortControllerRef.current?.abort();
        const controller = new AbortController();
        abortControllerRef.current = controller;

        try {
            const response = await fetch(
                quotationsExportPdf(quotationId, {
                    query: { download: 1 },
                }).url,
                {
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            );

            const validation = validatePdfResponse({
                ok: response.ok,
                redirected: response.redirected,
                status: response.status,
                contentType: response.headers.get('content-type'),
            });

            if (!validation.valid) {
                if (validation.requiresReload) {
                    reportError({
                        message:
                            'Tu sesión venció o no se pudo validar el archivo PDF. Recarga la página para volver a intentarlo.',
                        retry: () => window.location.reload(),
                    });
                } else {
                    reportError({
                        message:
                            'No se pudo preparar el PDF de la cotización. Inténtalo de nuevo.',
                        retry: () => {
                            void fetchAndSharePdf();
                        },
                    });
                }

                return;
            }

            const blob = await response.blob();
            const file = new File([blob], pdfFilename, {
                type: 'application/pdf',
            });

            const canShare = canShareFile(file);
            const initialAction = resolvePdfShareAction({ canShare });

            if (initialAction === 'download') {
                triggerPdfDownload(file);

                return;
            }

            try {
                await navigator.share({
                    files: [file],
                    title: shareTitle,
                });
            } catch (error) {
                const outcome = resolvePdfShareAction({
                    canShare: true,
                    error,
                    fromReadyFile: false,
                });

                if (outcome === 'needs_gesture') {
                    setReadyFile(file);
                } else if (outcome === 'download') {
                    triggerPdfDownload(file);
                }
            }
        } catch (error) {
            if (
                controller.signal.aborted ||
                (error instanceof DOMException && error.name === 'AbortError')
            ) {
                return;
            }

            reportError({
                message:
                    'No se pudo descargar el PDF. Revisa tu conexión e inténtalo de nuevo.',
                retry: () => {
                    void fetchAndSharePdf();
                },
            });
        } finally {
            if (abortControllerRef.current === controller) {
                abortControllerRef.current = null;
                setIsPreparing(false);
            }
        }
    };

    const handleClick = () => {
        if (readyFile) {
            void sharePreparedFile(readyFile);

            return;
        }

        void fetchAndSharePdf();
    };

    const label = isPreparing
        ? 'Preparando PDF…'
        : readyFile
          ? 'Compartir ahora'
          : 'Compartir PDF';

    const button = (
        <Button
            type="button"
            variant={variant}
            className={className}
            disabled={isPreparing}
            onClick={handleClick}
        >
            {isPreparing ? <Spinner /> : <FileText className="size-4" />}
            {label}
        </Button>
    );

    if (onErrorChange || !localError) {
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
