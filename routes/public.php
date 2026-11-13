<?php

declare(strict_types=1);

use App\Http\Controllers\PublicQrLandingController;
use App\Models\QrCode;
use App\Support\QrHost;
use Illuminate\Support\Facades\Route;

/*
| Rutas del dominio del QR de las estampitas (config/qr.php). Se cargan antes que web.php (bootstrap/app.php): la
| primera ruta que coincide gana, y sin ese orden la raíz de este dominio caería en la redirección al login del ERP.
| En este dominio solo responden estas rutas (IsolateQrHost); en el del ERP no existen.
*/

Route::domain(QrHost::name())
    ->middleware('throttle:qr-public')
    // Una sola vez para todo el grupo: una ruta nueva no puede olvidarlo. Acepta mayúsculas (el controlador busca en
    // minúsculas) y fija el largo para no chocar con `/up`, `/build` o `favicon.ico`.
    ->where(['token' => '[A-Za-z0-9]{'.QrCode::TOKEN_LENGTH.'}'])
    ->group(function (): void {
        // Quien borra el token de la URL llega al portafolio, no al ERP. 302: el portafolio puede cambiar de dirección.
        Route::redirect('/', QrHost::portfolioUrl())->name('qr.public.portfolio');

        Route::get('/{token}', [PublicQrLandingController::class, 'show'])->name('qr.public.show');
        Route::get('/{token}/documents/{document}', [PublicQrLandingController::class, 'downloadDocument'])
            ->name('qr.public.documents.download');
        Route::get('/{token}/product-documents/{document}', [PublicQrLandingController::class, 'downloadProductDocument'])
            ->name('qr.public.product-documents.download');
    });
