<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Services\AlertService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Los trabajos de recálculo de costo que agotan sus intentos quedan en `failed_jobs`, donde nadie los ve, y el precio o
 * el costo queda viejo hasta el próximo movimiento. Con esto dejan una alerta en la campana (una por elemento) que dice
 * qué no se recalculó y qué hacer, y la resuelven solos cuando un recálculo posterior del mismo elemento termina bien.
 */
trait ReportsRecalculationFailure
{
    /** La materia prima del recálculo, si el elemento es una: la alerta va atada a ella. */
    abstract protected function recalculationFailureRawMaterialId(): ?int;

    /** Qué no se recalculó y qué hacer, en español: es el mensaje de la alerta. */
    abstract protected function recalculationFailureMessage(): string;

    public function failed(?Throwable $exception = null): void
    {
        // Primero el log, con el detalle técnico: si la base es la que falla, la alerta tampoco se podrá crear.
        Log::error('Un recálculo de costo agotó sus intentos.', [
            'job' => static::class,
            'raw_material_id' => $this->recalculationFailureRawMaterialId(),
            'exception' => $exception,
        ]);

        app(AlertService::class)->raiseRecalculationFailure(
            $this->recalculationFailureRawMaterialId(),
            $this->recalculationFailureMessage(),
        );
    }

    protected function resolveRecalculationFailure(AlertService $alertService): void
    {
        $alertService->resolveRecalculationFailure(
            $this->recalculationFailureRawMaterialId(),
            $this->recalculationFailureMessage(),
        );
    }
}
