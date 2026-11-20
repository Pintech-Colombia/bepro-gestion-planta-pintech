<?php

declare(strict_types=1);

return [
    'auto_update_variant_price' => env('AUTO_UPDATE_VARIANT_PRICE', true),
    // Policies: conservative_max | weighted_average | last_lot
    // conservative_max is default to protect margin with volatile purchases.
    'raw_material_reference_price_policy' => env('RAW_MATERIAL_REFERENCE_PRICE_POLICY', 'conservative_max'),
    'lot_start_number' => (int) env('PRODUCTION_ORDER_LOT_START', 1),
    // Cuánto puede pasarse el consumo real de una materia prima de lo planeado en la OP (sumando sus pasos y sus ajustes
    // de línea) antes de pedir un motivo y crear la alerta de sobreconsumo. Regla del negocio, no del servidor: fija en
    // el código, sin `env()`, igual en todos los entornos; cambiarla es un PR. Provisional hasta J14.
    'consumption_tolerance_percentage' => '10.00',
];
