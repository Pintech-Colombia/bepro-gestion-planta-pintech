<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\QrCode;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode as QrCodeImage;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;

class QrImageService
{
    /**
     * La imagen del QR como descarga del ERP (Códigos QR y la tarjeta de la OP). Sin caché: si el QR se desactiva o
     * cambia de dominio, la descarga tiene que salir con el de ahora y no con la de ayer.
     */
    public function pngResponse(QrCode $qrCode): Response
    {
        return response($this->generatePng($qrCode), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    public function generatePng(QrCode $qrCode): string
    {
        $image = new QrCodeImage(
            data: $qrCode->landingUrl(),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
        );

        // TEMPORAL (2026-11-13): de vuelta a 130 px de 300 para comparar en la DYMO con la de 90 px, cuyo logo se mezcla
        // con el QR y no se nota. Decidir el tamaño tras la prueba impresa (Q7 del plan del QR).
        $logoPath = public_path('images/beprologoqr.png');
        $logo = file_exists($logoPath)
            ? new Logo(path: $logoPath, resizeToWidth: 130, punchoutBackground: false)
            : null;

        return (new PngWriter)->write($image, $logo)->getString();
    }
}
