<?php

declare(strict_types=1);

return [
    'auto_update_variant_price' => env('AUTO_UPDATE_VARIANT_PRICE', true),
    // Policies: conservative_max | weighted_average | last_lot
    // conservative_max is default to protect margin with volatile purchases.
    'raw_material_reference_price_policy' => env('RAW_MATERIAL_REFERENCE_PRICE_POLICY', 'conservative_max'),
    'lot_start_number' => (int) env('PRODUCTION_ORDER_LOT_START', 1),
    // Cuánto puede pasarse el consumo real de lo planeado en la OP (por materia prima, sumando sus pasos y sus ajustes de
    // línea) antes de pedir un motivo y crear la alerta de sobreconsumo. Es el general: una familia de materia prima puede
    // tener el suyo (Configuración → Familias; la jefa pidió 10 % para resinas y solventes), y el producto terminado que
    // va como ingrediente usa siempre este. Regla del negocio, no del servidor: fija en el código, sin `env()`, igual en
    // todos los entornos; cambiarla es un PR.
    'consumption_tolerance_percentage' => '5.00',
];
