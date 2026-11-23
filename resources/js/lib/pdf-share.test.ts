import { describe, expect, it } from 'vitest';

import {
    canBrowserSharePdf,
    isPdfContentType,
    quotationPdfFilename,
    quotationPdfTitle,
    resolvePdfShareAction,
    validatePdfResponse,
} from '@/lib/pdf-share';

describe('pdf-share', () => {
    describe('quotationPdfFilename y quotationPdfTitle', () => {
        it('usa el número de cotización cuando existe', () => {
            expect(quotationPdfFilename({ id: 9, quotation_number: 42 })).toBe(
                'COT. 42.pdf',
            );
            expect(quotationPdfTitle({ id: 9, quotation_number: 42 })).toBe(
                'Cotización 42',
            );
        });

        it('usa el id como respaldo si aún no hay número', () => {
            expect(
                quotationPdfFilename({ id: 9, quotation_number: null }),
            ).toBe('COT. 9.pdf');
            expect(quotationPdfTitle({ id: 9, quotation_number: null })).toBe(
                'Cotización 9',
            );
        });
    });

    describe('isPdfContentType', () => {
        it('acepta application/pdf con o sin parámetros', () => {
            expect(isPdfContentType('application/pdf')).toBe(true);
            expect(isPdfContentType('Application/PDF; charset=utf-8')).toBe(
                true,
            );
        });

        it('rechaza HTML u otros tipos', () => {
            expect(isPdfContentType('text/html; charset=UTF-8')).toBe(false);
            expect(isPdfContentType('application/json')).toBe(false);
            expect(isPdfContentType('')).toBe(false);
            expect(isPdfContentType(null)).toBe(false);
        });
    });

    describe('validatePdfResponse', () => {
        it('acepta una respuesta 200 sin redirección con Content-Type PDF', () => {
            expect(
                validatePdfResponse({
                    ok: true,
                    redirected: false,
                    status: 200,
                    contentType: 'application/pdf',
                }),
            ).toEqual({ valid: true });
        });

        it('marca sesión vencida cuando fetch siguió una redirección al login', () => {
            expect(
                validatePdfResponse({
                    ok: true,
                    redirected: true,
                    status: 200,
                    contentType: 'text/html; charset=UTF-8',
                }),
            ).toEqual({
                valid: false,
                reason: 'session_expired',
                requiresReload: true,
            });
        });

        it.each([401, 419])('marca sesión vencida ante estado %i', (status) => {
            expect(
                validatePdfResponse({
                    ok: false,
                    redirected: false,
                    status,
                    contentType: 'text/html',
                }),
            ).toEqual({
                valid: false,
                reason: 'session_expired',
                requiresReload: true,
            });
        });

        it('marca error HTTP recuperable sin recarga para 403 o 500', () => {
            expect(
                validatePdfResponse({
                    ok: false,
                    redirected: false,
                    status: 500,
                    contentType: 'text/html',
                }),
            ).toEqual({
                valid: false,
                reason: 'http_error',
                requiresReload: false,
            });
        });

        it('rechaza un 200 cuyo contenido no es PDF y pide recargar', () => {
            expect(
                validatePdfResponse({
                    ok: true,
                    redirected: false,
                    status: 200,
                    contentType: 'text/html; charset=UTF-8',
                }),
            ).toEqual({
                valid: false,
                reason: 'invalid_content_type',
                requiresReload: true,
            });
        });
    });

    describe('resolvePdfShareAction', () => {
        it('descarga cuando el navegador no puede compartir archivos', () => {
            expect(resolvePdfShareAction({ canShare: false })).toBe('download');
            expect(
                resolvePdfShareAction({
                    canShare: false,
                    error: new DOMException('Denied', 'NotAllowedError'),
                }),
            ).toBe('download');
        });

        it('comparte cuando el navegador lo soporta y no hubo error', () => {
            expect(resolvePdfShareAction({ canShare: true })).toBe('share');
        });

        it('ignora AbortError cuando el usuario cierra la hoja de compartir', () => {
            expect(
                resolvePdfShareAction({
                    canShare: true,
                    error: new DOMException('Cancelled', 'AbortError'),
                }),
            ).toBe('aborted');
        });

        it('pide segundo toque ante NotAllowedError en el primer intento (Safari iOS)', () => {
            expect(
                resolvePdfShareAction({
                    canShare: true,
                    error: new DOMException(
                        'Gesture expired',
                        'NotAllowedError',
                    ),
                }),
            ).toBe('needs_gesture');
        });

        it('descarga como respaldo si NotAllowedError se repite con el archivo listo', () => {
            expect(
                resolvePdfShareAction({
                    canShare: true,
                    error: new DOMException('Blocked', 'NotAllowedError'),
                    fromReadyFile: true,
                }),
            ).toBe('download');
        });

        it('descarga como respaldo ante cualquier otro error de navigator.share', () => {
            expect(
                resolvePdfShareAction({
                    canShare: true,
                    error: new TypeError('Share failed'),
                }),
            ).toBe('download');
        });
    });

    describe('canBrowserSharePdf', () => {
        it('devuelve false si faltan share o canShare', () => {
            expect(canBrowserSharePdf(undefined)).toBe(false);
            expect(canBrowserSharePdf({})).toBe(false);
            expect(
                canBrowserSharePdf({
                    share: async () => undefined,
                }),
            ).toBe(false);
        });

        it('delega en navigator.canShare con un archivo PDF', () => {
            let receivedFiles: File[] | undefined;

            const result = canBrowserSharePdf({
                share: async () => undefined,
                canShare: (data) => {
                    receivedFiles = data?.files;

                    return true;
                },
            });

            expect(result).toBe(true);
            expect(receivedFiles).toHaveLength(1);
            expect(receivedFiles?.[0].type).toBe('application/pdf');
        });

        it('devuelve false si navigator.canShare devuelve false o lanza error', () => {
            expect(
                canBrowserSharePdf({
                    share: async () => undefined,
                    canShare: () => false,
                }),
            ).toBe(false);

            expect(
                canBrowserSharePdf({
                    share: async () => undefined,
                    canShare: () => {
                        throw new TypeError('Not supported');
                    },
                }),
            ).toBe(false);
        });
    });
});
