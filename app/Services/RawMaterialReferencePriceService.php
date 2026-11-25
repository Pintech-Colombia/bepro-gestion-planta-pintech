<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\RawMaterial;
use Illuminate\Support\Facades\DB;

class RawMaterialReferencePriceService
{
    /** Políticas de precio de referencia (docs/POLITICA_COSTOS_MATERIA_PRIMA.md). La que se usa está en config/production.php. */
    public const POLICIES = ['conservative_max', 'weighted_average', 'last_lot'];

    public function __construct(
        private readonly DecimalCalculator $calculator
    ) {}

    public function syncRawMaterialCurrentPrice(int $rawMaterialId): bool
    {
        return DB::transaction(function () use ($rawMaterialId): bool {
            // Fila completa: se guarda después y la auditoría compara contra lo leído. Con columnas parciales registraría
            // como cambio cada atributo auditado que no se leyó (código, unidad, stock mínimo, activo).
            $rawMaterial = RawMaterial::query()
                ->lockForUpdate()
                ->find($rawMaterialId);

            // Sin control de inventario el precio se escribe a mano (agua, etiquetas): un lote, aunque exista (una compra
            // registrada por error, o de cuando sí controlaba inventario), no debe reemplazarlo.
            if ($rawMaterial === null || ! $rawMaterial->tracks_inventory) {
                return false;
            }

            $currentPrice = $rawMaterial->current_price !== null ? (string) $rawMaterial->current_price : null;
            $referencePrice = $this->calculateReferencePrice($rawMaterialId, $currentPrice);

            if ($referencePrice === null) {
                return false;
            }

            if ($this->pricesAreEqual($currentPrice, $referencePrice)) {
                return false;
            }

            $rawMaterial->update([
                'previous_price' => $rawMaterial->current_price,
                'current_price' => $referencePrice,
            ]);

            return true;
        }, attempts: 3);
    }

    public function calculateReferencePrice(int $rawMaterialId, ?string $currentPrice = null): ?string
    {
        $latestLotPrice = InventoryBatch::query()
            ->where('raw_material_id', $rawMaterialId)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->value('unit_price');
        $availableStats = InventoryBatch::query()
            ->where('raw_material_id', $rawMaterialId)
            ->where('remaining_quantity', '>', 0)
            ->selectRaw('
                SUM(remaining_quantity * unit_price) as weighted_total,
                SUM(remaining_quantity) as total_quantity,
                MAX(unit_price) as highest_unit_price
            ')
            ->first();

        $weightedAveragePrice = null;
        if ($availableStats !== null && $availableStats->total_quantity !== null && ! $this->calculator->isZero((string) $availableStats->total_quantity)) {
            $weightedAveragePrice = $this->calculator->div(
                (string) $availableStats->weighted_total,
                (string) $availableStats->total_quantity,
                4
            );
        }

        $highestAvailableLotPrice = $availableStats?->highest_unit_price;

        $policy = (string) config('production.raw_material_reference_price_policy', 'conservative_max');

        $referencePrice = match ($policy) {
            'last_lot' => $this->firstAvailableNumericValue([$latestLotPrice, $weightedAveragePrice, $currentPrice]),
            'weighted_average' => $this->firstAvailableNumericValue([$weightedAveragePrice, $latestLotPrice, $currentPrice]),
            'conservative_max' => $this->firstAvailableNumericValue([$highestAvailableLotPrice, $currentPrice, $latestLotPrice]),
            // Un valor mal escrito no cae en silencio en otra política: el recálculo falla y queda en failed_jobs.
            default => throw new \InvalidArgumentException(
                "La política de precio de referencia «{$policy}» no existe (config/production.php). Válidas: "
                    .implode(', ', self::POLICIES).'.'
            ),
        };

        return $referencePrice !== null ? $this->calculator->round($referencePrice, 4) : null;
    }

    /**
     * @param  array<int, float|int|string|null>  $values
     */
    private function firstAvailableNumericValue(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function pricesAreEqual(?string $priceA, ?string $priceB): bool
    {
        return $this->calculator->sameOrBothNull($priceA, $priceB);
    }
}
