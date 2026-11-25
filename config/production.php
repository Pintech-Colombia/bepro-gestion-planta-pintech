<?php

declare(strict_types=1);

return [
    // Reglas del negocio, no del servidor: fijas en el código, iguales en todos los entornos; cambiarlas es un PR.

    // Al recalcular el costo de un producto, actualizar también el precio de sus presentaciones.
    'auto_update_variant_price' => true,
    // Precio de referencia de la materia prima: conservative_max | weighted_average | last_lot
    // (docs/POLITICA_COSTOS_MATERIA_PRIMA.md). conservative_max protege el margen con compras volátiles.
    'raw_material_reference_price_policy' => 'conservative_max',
    // Lote de la primera OP en una base nueva; las siguientes continúan desde el último. Antes de producción se fija el
    // que siga al último lote que usó la planta (checklist de preproducción).
    'lot_start_number' => 1,
    // Cuánto puede pasarse el consumo real de lo planeado en la OP (por materia prima, sumando sus pasos y sus ajustes de
    // línea) antes de pedir un motivo y crear la alerta de sobreconsumo. Es el general: una familia de materia prima puede
    // tener el suyo (Configuración → Familias; la jefa pidió 10 % para resinas y solventes), y el producto terminado que
    // va como ingrediente usa siempre este.
    'consumption_tolerance_percentage' => '5.00',
];
