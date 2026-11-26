<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RecalculationKind;
use App\Jobs\Concerns\ReportsRecalculationFailure;
use App\Models\UnitOfMeasure;
use App\Services\AlertService;
use App\Services\ProductionCostRecalculationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula el costo de los productos cuya fórmula de referencia convierte con una unidad cuya equivalencia se corrigió
 * (galón de 3,785 a 3,7854 L). Fuera de la petición, como el cambio de precio de una materia prima: una unidad puede
 * estar en cientos de fórmulas.
 */
class RecalculateUnitDependentCosts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use ReportsRecalculationFailure;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $unitOfMeasureId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->unitOfMeasureId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("unit-dependent-costs:{$this->unitOfMeasureId}"))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function handle(
        ProductionCostRecalculationService $productionCostRecalculationService,
        AlertService $alertService,
    ): void {
        $productionCostRecalculationService->recalculateForUnit($this->unitOfMeasureId);

        $this->resolveRecalculationFailure($alertService);
    }

    protected function recalculationFailureKey(): string
    {
        return RecalculationKind::UnitCosts->sourceKey($this->unitOfMeasureId);
    }

    protected function recalculationFailureRawMaterialId(): ?int
    {
        return null;
    }

    protected function recalculationFailureMessage(): string
    {
        $unit = UnitOfMeasure::query()->whereKey($this->unitOfMeasureId)->value('name') ?? '#'.$this->unitOfMeasureId;

        return __('No se pudo recalcular el costo de los productos que usan la unidad :unit. Pida a sistemas que ejecute «php artisan costs:recalculate-product» en el servidor.', [
            'unit' => $unit,
        ]);
    }
}
