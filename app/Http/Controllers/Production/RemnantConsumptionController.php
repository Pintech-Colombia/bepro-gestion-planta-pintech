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
use App\Services\AvailableRemnantsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RemnantConsumptionController extends Controller
{
    use RejectsStaleOrderWrites;

    public function __construct(
        private readonly AvailableRemnantsService $availableRemnants,
    ) {}

    /**
     * Refresca la lista de saldos que la orden puede planear: los de su bodega con kilos libres, de cualquier producto
     * (decisión del 2026-09-23), cada uno con el producto y el color de su orden de origen.
     */
    public function availableRemnants(ProductionOrder $productionOrder): JsonResponse
    {
        $this->authorize('planRemnant', $productionOrder);

        return response()->json($this->availableRemnants->forOrder($productionOrder));
    }

    /**
     * Agrega a la orden una línea planeada con el saldo indicado. El saldo se descuenta al completar la orden.
     */
    public function store(
        PlanRemnantConsumptionRequest $request,
        ProductionOrder $productionOrder,
        PlanRemnantConsumptionAction $planRemnantConsumption
    ): RedirectResponse {
        $remnant = ProductionRemnant::findOrFail($request->validated('remnant_id'));

        try {
            $planRemnantConsumption->execute(
                remnant: $remnant,
                targetOrder: $productionOrder,
                quantityKg: (string) $request->validated('quantity_kg'),
                user: $request->user(),
                notes: $request->validated('notes')
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
