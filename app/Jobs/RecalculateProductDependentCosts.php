<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\ReportsRecalculationFailure;
use App\Models\Product;
use App\Services\AlertService;
use App\Services\ProductionCostRecalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula en segundo plano los productos que llevan este producto en su fórmula de referencia (P13, D-7 de «Plan — PT
 * consumible»). Cada uno, al recalcularse, despacha el suyo: la cadena MP → blanco → RAL 7016 → … avanza un nivel por
 * trabajo. Los ciclos se bloquean al guardar la fórmula; el tope de profundidad es solo una red de seguridad.
 */
class RecalculateProductDependentCosts implements ShouldQueue
{
    use Queueable;
    use ReportsRecalculationFailure;

    public const MAX_DEPTH = 10;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $productId,
        public readonly int $depth = 1,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        ProductionCostRecalculationService $productionCostRecalculationService,
        AlertService $alertService,
    ): void {
        if ($this->depth > self::MAX_DEPTH) {
            Log::warning('Cadena de costos de productos más profunda que el tope: se detiene.', [
                'product_id' => $this->productId,
                'depth' => $this->depth,
            ]);

            return;
        }

        $productionCostRecalculationService->recalculateDependentsOf($this->productId, $this->depth);

        $this->resolveRecalculationFailure($alertService);
    }

    protected function recalculationFailureRawMaterialId(): ?int
    {
        return null;
    }

    protected function recalculationFailureMessage(): string
    {
        $name = Product::query()->whereKey($this->productId)->value('name') ?? '#'.$this->productId;

        return __('No se pudo recalcular el costo de los productos que llevan :product en su fórmula. Pida a sistemas que ejecute «php artisan costs:recalculate-product» en el servidor.', [
            'product' => $name,
        ]);
    }
}
