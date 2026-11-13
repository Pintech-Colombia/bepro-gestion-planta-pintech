<?php

declare(strict_types=1);

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Services\QrImageService;
use Illuminate\Http\Response;

/**
 * La imagen del QR de la tarjeta de la OP. La pide quien ve la orden; la de Códigos QR pide otro permiso.
 */
class ProductionOrderQrImageController extends Controller
{
    public function __construct(
        private readonly QrImageService $qrImageService,
    ) {}

    public function __invoke(ProductionOrder $productionOrder): Response
    {
        $this->authorize('view', $productionOrder);

        // Como la tarjeta: sin QR o con el QR desactivado no hay imagen que mostrar.
        $qrCode = $productionOrder->qrCode;
        abort_unless($qrCode !== null && $qrCode->is_active, 404);

        return $this->qrImageService->pngResponse($qrCode);
    }
}
