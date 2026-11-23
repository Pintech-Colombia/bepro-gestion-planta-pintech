<?php

declare(strict_types=1);

namespace App\Actions\Production;

use App\Models\ProductionOrder;
use App\Services\TimezoneService;

class BuildProductionOrderExportDataAction
{
    public function __construct(
        private readonly BuildProductionOrderShowDataAction $buildShowData,
        private readonly BuildProductionOrderPdfMaterialsAction $buildPdfMaterials,
        private readonly TimezoneService $timezone,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ProductionOrder $productionOrder): array
    {
        // El PDF y el Excel son la hoja de planta: no llevan costos para ningún rol; los costos se ven en el detalle de
        // la OP (decisión del usuario, 2026-11-23).
        $orderData = $this->buildShowData->execute($productionOrder, includeCosts: false);
        $orderData['pdf_materials'] = $this->buildPdfMaterials->execute($productionOrder);

        // «1623 del 04 de octubre 2026», igual en el PDF y el Excel: el lote con su fecha de fabricación (creación de la
        // OP, en fecha de planta), la del certificado y la estampita. La planeada es referencia interna de planta (B55).
        $manufacturedAt = $this->timezone->toPlantTime($productionOrder->getManufacturingDate());
        $manufacturedOn = $manufacturedAt?->translatedFormat('d \d\e F Y');
        // La FECHA de la hoja también es la de fabricación: la planeada no se imprime.
        $orderData['manufactured_on'] = $manufacturedAt?->format('Y-m-d');
        $orderData['lot_caption'] = $manufacturedOn !== null
            ? "{$productionOrder->lot_number} del {$manufacturedOn}"
            : (string) $productionOrder->lot_number;

        return $orderData;
    }
}
