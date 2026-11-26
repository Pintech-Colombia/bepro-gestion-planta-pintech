<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RecalculationKind;
use App\Jobs\Concerns\ReportsRecalculationFailure;
use App\Models\RawMaterial;
use App\Services\AlertService;
use App\Services\RawMaterialReferencePriceService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula el precio de referencia de una materia prima desde sus compras. Si cambia, encola el recálculo de los costos
 * que dependen de ella (`RecalculateRawMaterialDependentCosts`) en vez de hacerlo aquí: el precio queda guardado en su
 * propia transacción, y si los costos fallaran después, un reintento de este trabajo vería el precio ya igual y no los
 * recalcularía nunca. Separados, cada uno se reintenta y avisa por su cuenta.
 */
class RecalculateRawMaterialReferencePrice implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use ReportsRecalculationFailure;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $rawMaterialId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->rawMaterialId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    // NOTA: ShouldBeUniqueUntilProcessing previene duplicados en cola;
    //       WithoutOverlapping protege la ejecución misma (defense-in-depth)
    //       ya que la uniqueness se libera al iniciar procesamiento.
    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("raw-material-reference-price:{$this->rawMaterialId}"))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function handle(
        RawMaterialReferencePriceService $rawMaterialReferencePriceService,
        AlertService $alertService,
    ): void {
        $currentPriceChanged = $rawMaterialReferencePriceService
            ->syncRawMaterialCurrentPrice($this->rawMaterialId);

        if ($currentPriceChanged) {
            $this->evaluatePriceVariationAlert($alertService);
            RecalculateRawMaterialDependentCosts::dispatch($this->rawMaterialId);
        }

        $this->resolveRecalculationFailure($alertService);
    }

    protected function recalculationFailureKey(): string
    {
        return RecalculationKind::ReferencePrice->sourceKey($this->rawMaterialId);
    }

    protected function recalculationFailureRawMaterialId(): ?int
    {
        return $this->rawMaterialId;
    }

    protected function recalculationFailureMessage(): string
    {
        $code = RawMaterial::query()->whereKey($this->rawMaterialId)->value('code') ?? '#'.$this->rawMaterialId;

        return __('No se pudo recalcular el precio de referencia de :code. Pida a sistemas que ejecute «php artisan costs:recalculate-raw-material :id» en el servidor.', [
            'code' => $code,
            'id' => $this->rawMaterialId,
        ]);
    }

    private function evaluatePriceVariationAlert(AlertService $alertService): void
    {
        $rawMaterial = RawMaterial::query()
            ->select(['id', 'code', 'current_price', 'previous_price', 'price_variation_threshold'])
            ->find($this->rawMaterialId);

        if ($rawMaterial === null) {
            return;
        }

        $alertService->evaluatePriceVariation(
            rawMaterial: $rawMaterial,
            previousPrice: $rawMaterial->previous_price !== null ? (string) $rawMaterial->previous_price : null,
            newPrice: $rawMaterial->current_price !== null ? (string) $rawMaterial->current_price : null,
        );
    }
}
