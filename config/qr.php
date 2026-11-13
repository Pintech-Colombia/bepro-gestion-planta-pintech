<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Dominio del QR de las estampitas
    |--------------------------------------------------------------------------
    |
    | El QR impreso apunta a `https://{host}/{token}`, un dominio aparte del ERP: en él solo responden la página pública
    | del lote y sus descargas (routes/public.php). Nunca vacío ni igual al dominio de APP_URL (App\Support\QrHost lo
    | comprueba al arrancar). `qr.localhost` sirve en local, en las pruebas, en el CI y al construir la imagen; en un
    | servidor tiene que ir el dominio real, o no se puede armar la URL del QR.
    |
    */

    'host' => env('QR_HOST', 'qr.localhost'),

    /*
    |--------------------------------------------------------------------------
    | Portafolio de la empresa
    |--------------------------------------------------------------------------
    |
    | A dónde lleva la raíz del dominio del QR y el enlace de la página «Este lote no está disponible».
    |
    */

    'portfolio_url' => env('QR_PORTFOLIO_URL', 'https://beprocoatings.com'),

];
