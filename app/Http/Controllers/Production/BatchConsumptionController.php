<?php

declare(strict_types=1);

namespace App\Http\Controllers\Production;

use App\Actions\Production\PlanBatchConsumptionAction;
use App\Actions\Production\RemoveBatchConsumptionAction;
use App\Actions\Production\UpdateBatchConsumptionUnitsAction;
use App\Concerns\RejectsStaleOrderWrites;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\PlanBatchConsumptionRequest;
use App\Http\Requests\Production\UpdateBatchConsumptionRequest;
use App\Models\FinishedProductBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatchConsumption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Los lotes de PT que una OP usa como fuente de sus líneas de «Productos a usar»: agregar, cambiar las unidades y quitar
 * mientras la orden está abierta. Las unidades salen del inventario al completar la orden.
 */
class BatchConsumptionController extends Controller
{
    use RejectsStaleOrderWrites;

    public function store(
        PlanBatchConsumptionRequest $request,
        ProductionOrder $productionOrder,
        PlanBatchConsumptionAction $planBatchConsumption,
    ): RedirectResponse {
        $batch = FinishedProductBatch::findOrFail($request->validated('finished_product_batch_id'));
        $lineId = $request->validated('production_order_product_line_id');

        try {
            $planBatchConsumption->execute(
                order: $productionOrder,
                batch: $batch,
                units: (string) $request->validated('units'),
                user: $request->user(),
                lineId: $lineId !== null ? (int) $lineId : null,
            );
        } catch (\DomainException $exception) {
            return $this->staleOrderResponse($exception);
        }

        Inertia::flash('success', __('Lote agregado a la orden.'));

        return back();
    }

    public function update(
        UpdateBatchConsumptionRequest $request,
        ProductionOrder $productionOrder,
        ProductionOrderBatchConsumption $batchConsumption,
        UpdateBatchConsumptionUnitsAction $updateUnits,
    ): RedirectResponse {
        if ((int) $batchConsumption->production_order_id !== $productionOrder->id) {
            abort(404);
        }

        try {
            $updateUnits->execute($productionOrder, $batchConsumption, (string) $request->validated('units'), $request->user());
        } catch (\DomainException $exception) {
            return $this->staleOrderResponse($exception);
        }

        Inertia::flash('success', __('Unidades del lote actualizadas.'));

        return back();
    }

    public function destroy(
        Request $request,
        ProductionOrder $productionOrder,
        ProductionOrderBatchConsumption $batchConsumption,
        RemoveBatchConsumptionAction $removeBatchConsumption,
    ): RedirectResponse {
        $this->authorize('planRemnant', $productionOrder);

        if ((int) $batchConsumption->production_order_id !== $productionOrder->id) {
            abort(404);
        }

        try {
            $removeBatchConsumption->execute($productionOrder, $batchConsumption, $request->user());
        } catch (\DomainException $exception) {
            return $this->staleOrderResponse($exception);
        }

        Inertia::flash('success', __('Lote quitado de la orden.'));

        return back();
    }
}
