<?php

declare(strict_types=1);

namespace App\Http\Controllers\Production;

use App\Actions\Production\CorrectRemnantAction;
use App\Actions\Production\WriteOffRemnantAction;
use App\Enums\Permission;
use App\Enums\RemnantStatus;
use App\Filters\RemnantFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\CorrectRemnantRequest;
use App\Http\Requests\Production\IndexRemnantRequest;
use App\Http\Requests\Production\WriteOffRemnantRequest;
use App\Models\ProductionOrder;
use App\Models\ProductionRemnant;
use App\Models\ProductionRemnantMovement;
use App\Models\Warehouse;
use App\Services\AvailableRemnantsService;
use App\Services\DecimalCalculator;
use App\Support\EnumOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RemnantController extends Controller
{
    public function __construct(
        private readonly AvailableRemnantsService $availableRemnants,
        private readonly DecimalCalculator $calculator,
        private readonly WriteOffRemnantAction $writeOffRemnant,
        private readonly CorrectRemnantAction $correctRemnant,
    ) {}

    /**
     * Display a listing of the available production remnants.
     */
    public function index(IndexRemnantRequest $request): Response
    {
        $canViewCosts = $request->user()?->can(Permission::CostsView->value) ?? false;

        $remnants = (new RemnantFilter($request))
            ->apply(ProductionRemnant::query())
            ->with([
                'sourceOrder:id,order_number,color',
                'product:id,name',
                'warehouse:id,name',
            ])
            // `latest('id')` desempata: sin él la paginación puede repetir o saltarse registros.
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->onEachSide(1)
            ->withQueryString();

        // Lo planeado en órdenes abiertas que todavía no se descontó, por orden: la misma cuenta que limita lo que se puede
        // planear, y la que ven los diálogos de baja y corrección.
        $plannedOrders = $this->availableRemnants->plannedOrders($remnants->getCollection()->modelKeys());

        $remnants->through(function (ProductionRemnant $remnant) use ($plannedOrders, $canViewCosts): array {
            $orders = $plannedOrders[$remnant->id] ?? [];
            $committed = $this->availableRemnants->plannedTotal($orders);

            return [
                'id' => $remnant->id,
                'source_order_id' => $remnant->source_order_id,
                'source_order_number' => $remnant->sourceOrder->order_number,
                'product_id' => $remnant->product_id,
                // Con el color de la orden de origen (3.4): el saldo de un RAL 3020 no es el del producto base.
                'product_name' => ProductionOrder::nameWithColor($remnant->product->name, $remnant->sourceOrder->color),
                'warehouse_id' => $remnant->warehouse_id,
                'warehouse_name' => $remnant->warehouse->name,
                'available_quantity_gallons' => (float) $remnant->available_quantity_gallons,
                'available_quantity_kg' => (float) $remnant->available_quantity_kg,
                'committed_quantity_kg' => $committed === null ? null : (float) $committed,
                'planned_orders' => $orders,
                'density_kg_per_gallon' => (float) $remnant->density_kg_per_gallon,
                'cost_per_gallon' => $canViewCosts && $remnant->cost_per_gallon !== null ? (float) $remnant->cost_per_gallon : null,
                'status' => $remnant->status->value,
                'status_label' => $remnant->status->label(),
                'created_at' => $remnant->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('Production/Remnants/Index', [
            'remnants' => $remnants,
            'filters' => $request->validated(),
            'statusOptions' => EnumOptions::for(RemnantStatus::cases()),
            'can' => [
                'viewCosts' => $canViewCosts,
                'adjust' => $request->user()?->can(Permission::ProductionRemnantsAdjust->value) ?? false,
            ],
            'warehouseOptions' => Warehouse::query()
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(fn (Warehouse $warehouse) => [
                    'value' => (string) $warehouse->id,
                    'label' => $warehouse->name,
                ])
                ->all(),
        ]);
    }

    /**
     * El detalle de un saldo: sus datos, la OP de origen, lo planeado en órdenes abiertas y su historial.
     */
    public function show(Request $request, ProductionRemnant $remnant): Response
    {
        $user = $request->user();
        $canViewCosts = $user->can(Permission::CostsView->value);

        $remnant->load([
            'sourceOrder:id,order_number,lot_number,color',
            'product:id,name',
            'warehouse:id,name',
            'createdBy:id,name',
        ]);

        $plannedOrders = $this->availableRemnants->plannedOrders([$remnant->id])[$remnant->id] ?? [];

        $movements = $remnant->movements()
            ->with(['productionOrder:id,order_number', 'user:id,name'])
            // `latest('id')` desempata: dos movimientos del mismo instante (completar una OP) salen en su orden.
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn (ProductionRemnantMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type->value,
                // Sin signo y con su dirección: la pantalla pone «+» o «−» y el tono (DESIGN.md §4, movimientos).
                'direction' => $movement->decreasesBalance() ? 'out' : 'in',
                'quantity_kg' => $this->calculator->abs((string) $movement->quantity_kg, 4),
                'balance_after_kg' => (string) $movement->balance_after_kg,
                'production_order' => $movement->productionOrder === null ? null : [
                    'id' => $movement->productionOrder->id,
                    'order_number' => $movement->productionOrder->order_number,
                ],
                'user_name' => $movement->user?->name,
                'notes' => $movement->notes,
                'created_at' => $movement->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Production/Remnants/Show', [
            'remnant' => [
                'id' => $remnant->id,
                'source_order_id' => $remnant->source_order_id,
                'source_order_number' => $remnant->sourceOrder->order_number,
                'source_lot_number' => $remnant->sourceOrder->lot_number,
                'product_name' => ProductionOrder::nameWithColor($remnant->product->name, $remnant->sourceOrder->color),
                'warehouse_name' => $remnant->warehouse->name,
                'original_quantity_kg' => (string) $remnant->original_quantity_kg,
                'original_quantity_gallons' => (string) $remnant->original_quantity_gallons,
                'available_quantity_kg' => (string) $remnant->available_quantity_kg,
                'available_quantity_gallons' => (string) $remnant->available_quantity_gallons,
                'committed_quantity_kg' => $this->availableRemnants->plannedTotal($plannedOrders),
                'planned_orders' => $plannedOrders,
                'density_kg_per_gallon' => (string) $remnant->density_kg_per_gallon,
                'cost_per_gallon' => $canViewCosts && $remnant->cost_per_gallon !== null ? (string) $remnant->cost_per_gallon : null,
                'status' => $remnant->status->value,
                'notes' => $remnant->notes,
                'created_by_name' => $remnant->createdBy?->name,
                'created_at' => $remnant->created_at?->toIso8601String(),
            ],
            'movements' => $movements,
            'can' => [
                'viewCosts' => $canViewCosts,
                // La orden de origen y las de cada movimiento enlazan a la OP, que pide otro permiso: sin él, son texto.
                'viewProductionOrders' => $user->can(Permission::ProductionOrdersView->value),
                'adjust' => $user->can('adjust', $remnant),
            ],
        ]);
    }

    /**
     * Dar de baja un saldo que ya no sirve: queda en 0 kg, con la nota en su historial.
     */
    public function writeOff(WriteOffRemnantRequest $request, ProductionRemnant $remnant): RedirectResponse
    {
        $this->writeOffRemnant->execute($remnant, $request->validated('notes'), $request->user());

        Inertia::flash('success', 'Saldo dado de baja.');

        return redirect()->back();
    }

    /**
     * Corregir los kilos de un saldo a lo que se pesó, con la nota en su historial.
     */
    public function correct(CorrectRemnantRequest $request, ProductionRemnant $remnant): RedirectResponse
    {
        $this->correctRemnant->execute(
            $remnant,
            (string) $request->validated('quantity_kg'),
            $request->validated('notes'),
            $request->user(),
        );

        Inertia::flash('success', 'Kilos del saldo corregidos.');

        return redirect()->back();
    }
}
