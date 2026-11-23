<?php

declare(strict_types=1);

namespace App\Http\Controllers\Production;

use App\Actions\Production\PlanRemnantConsumptionAction;
use App\Actions\Production\RemoveRemnantConsumptionAction;
use App\Concerns\RejectsStaleOrderWrites;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\PlanRemnantConsumptionRequest;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\RemnantConsumption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RemnantConsumptionController extends Controller
{
    use RejectsStaleOrderWrites;

    /**
     * Agrega a la orden una línea planeada con el saldo indicado. El saldo se descuenta al completar la orden.
     */
    public function store(
        PlanRemnantConsumptionRequest $request,
        ProductionOrder $productionOrder,
        PlanRemnantConsumptionAction $planRemnantConsumption
    ): RedirectResponse {
        $remnant = ProductionRemnant::findOrFail($request->validated('remnant_id'));
        $lineId = $request->validated('production_order_product_line_id');

        try {
            $planRemnantConsumption->execute(
                remnant: $remnant,
                targetOrder: $productionOrder,
                quantityKg: (string) $request->validated('quantity_kg'),
                user: $request->user(),
                notes: $request->validated('notes'),
                lineId: $lineId !== null ? (int) $lineId : null,
            );
        } catch (\DomainException $exception) {
            return $this->staleOrderResponse($exception);
        }

        Inertia::flash('success', __('Saldo agregado a la orden.'));

        return back();
    }

    /**
     * Quita de la orden una línea de saldo que todavía no se aplicó.
     */
    public function destroy(
        Request $request,
        ProductionOrder $productionOrder,
        RemnantConsumption $consumption,
        RemoveRemnantConsumptionAction $removeRemnantConsumption
    ): RedirectResponse {
        $this->authorize('planRemnant', $productionOrder);

        if ((int) $consumption->target_order_id !== $productionOrder->id) {
            abort(404);
        }

        try {
            $removeRemnantConsumption->execute($productionOrder, $consumption, $request->user());
        } catch (\DomainException $exception) {
            return $this->staleOrderResponse($exception);
        }

        Inertia::flash('success', __('Saldo quitado de la orden.'));

        return back();
    }
}
