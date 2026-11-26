<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Actions\RawMaterials\UpdateRawMaterialAction;
use App\Actions\Shared\DeleteUnusedRecordAction;
use App\Enums\Permission;
use App\Filters\RawMaterialFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\RawMaterials\IndexRawMaterialRequest;
use App\Http\Requests\RawMaterials\ShowRawMaterialRequest;
use App\Http\Requests\RawMaterials\StoreRawMaterialRequest;
use App\Http\Requests\RawMaterials\UpdateRawMaterialRequest;
use App\Models\InventoryBatch;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\DecimalCalculator;
use App\Services\RawMaterialUsageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RawMaterialController extends Controller
{
    public function __construct(
        private readonly DeleteUnusedRecordAction $deleteUnused,
        private readonly UpdateRawMaterialAction $updateRawMaterial,
        private readonly RawMaterialUsageService $usage,
        private readonly DecimalCalculator $calculator,
    ) {}

    /**
     * Display a listing of the raw materials.
     */
    public function index(IndexRawMaterialRequest $request): Response
    {
        $user = $request->user();
        $canViewCosts = $user?->can(Permission::CostsView->value) ?? false;

        $query = (new RawMaterialFilter($request))
            ->apply(RawMaterial::query())
            ->with([
                'category:id,name,type',
                'unitOfMeasure:id,name,symbol',
            ])
            ->withSum('inventoryBatches as available_stock', 'remaining_quantity');

        // Si tiene actividad decide si se puede eliminar; la lista de usos vive en RawMaterialUsageService.
        $rawMaterials = $this->usage->withActivityFlags($query)
            ->withCount(['alerts as active_alerts_count' => fn ($query) => $query->where('is_resolved', false)])
            ->withExists(['alerts as has_critical_alert' => fn ($query) => $query
                ->where('is_resolved', false)
                ->where('severity', 'alta')])
            ->latest('id')
            ->paginate(15)
            ->onEachSide(1)
            ->withQueryString()
            ->through(function (RawMaterial $rawMaterial) use ($user, $canViewCosts): array {
                $hasActivity = $this->usage->hasActivity($rawMaterial);

                return [
                    'id' => $rawMaterial->id,
                    'code' => $rawMaterial->code,
                    'current_price' => $canViewCosts ? $rawMaterial->current_price : null,
                    'previous_price' => $canViewCosts ? $rawMaterial->previous_price : null,
                    'minimum_stock' => $rawMaterial->minimum_stock,
                    'available_stock' => $rawMaterial->available_stock ?? 0,
                    'has_available_stock' => (float) ($rawMaterial->available_stock ?? 0) > 0,
                    'has_activity' => $hasActivity,
                    'alert_days_before_expiry' => $rawMaterial->alert_days_before_expiry,
                    'active_alerts_count' => (int) ($rawMaterial->active_alerts_count ?? 0),
                    'has_critical_alert' => (bool) ($rawMaterial->has_critical_alert ?? false),
                    'is_active' => $rawMaterial->is_active,
                    'category' => $rawMaterial->category ? [
                        'id' => $rawMaterial->category->id,
                        'name' => $rawMaterial->category->name,
                        'type_label' => $rawMaterial->category->type->label(),
                    ] : null,
                    'unit_of_measure' => $rawMaterial->unitOfMeasure ? [
                        'id' => $rawMaterial->unitOfMeasure->id,
                        'name' => $rawMaterial->unitOfMeasure->name,
                        'symbol' => $rawMaterial->unitOfMeasure->symbol,
                    ] : null,
                    'can' => [
                        'view' => Gate::forUser($user)->allows('view', $rawMaterial),
                        'update' => Gate::forUser($user)->allows('update', $rawMaterial),
                        // Activa: se desactiva o se elimina. Inactiva: solo se puede eliminar, si nunca se usó.
                        'delete' => $rawMaterial->is_active
                            ? Gate::forUser($user)->allows('deactivate', $rawMaterial)
                            : $this->deletesPermanently($user, $rawMaterial, $hasActivity),
                        'reactivate' => Gate::forUser($user)->allows('reactivate', $rawMaterial) && ! $rawMaterial->is_active,
                        'deletePermanently' => $this->deletesPermanently($user, $rawMaterial, $hasActivity),
                    ],
                ];
            });

        return Inertia::render('Inventory/RawMaterials/Index', [
            'rawMaterials' => $rawMaterials,
            'filters' => $request->validated(),
            // Todas, también las inactivas: sus materias primas se siguen buscando.
            'familyOptions' => RawMaterialFamily::options(),
            'can' => [
                'create' => Gate::allows('create', RawMaterial::class),
                'view_costs' => $canViewCosts,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RawMaterial::class);

        return Inertia::render('Inventory/RawMaterials/Create', [
            'can' => ['updateCosts' => Gate::allows(Permission::CostsUpdate->value)],
            'categories' => $this->categoryOptions(RawMaterialCategory::query()->selectable()),
            'units' => UnitOfMeasure::query()
                ->selectable()
                ->select('id', 'name', 'symbol')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreRawMaterialRequest $request): RedirectResponse
    {
        $this->authorize('create', RawMaterial::class);

        RawMaterial::create($request->validated());

        Inertia::flash('success', __('Materia prima registrada exitosamente.'));

        return redirect()
            ->route('raw-materials.index');
    }

    /**
     * Display the specified raw material with its batches and activity status.
     */
    public function show(ShowRawMaterialRequest $request, RawMaterial $rawMaterial): Response
    {
        $canViewCosts = $request->user()?->can(Permission::CostsView->value) ?? false;
        $batchesTab = $request->batchesTab();

        $rawMaterial->load([
            'category:id,name,type,family_id',
            'category.family:id,name',
            'unitOfMeasure:id,name,symbol',
        ]);

        // Lotes con existencia en orden FIFO (el mismo que consume la OP). Se selecciona `warehouse_id` para
        // señalar el primer lote FIFO por bodega.
        $activeBatchModels = $rawMaterial->inventoryBatches()
            ->available()
            ->fifoOrder()
            ->select(
                'id',
                'raw_material_id',
                'warehouse_id',
                'lot_number',
                'supplier_id',
                'initial_quantity',
                'remaining_quantity',
                'unit_price',
                'entry_date',
                'expiry_date',
            )
            ->with('supplier:id,name')
            ->get();

        $seenWarehouses = [];
        $activeBatches = $activeBatchModels->map(function (InventoryBatch $batch) use (&$seenWarehouses, $canViewCosts): array {
            $consumesFirst = ! isset($seenWarehouses[$batch->warehouse_id]);
            $seenWarehouses[$batch->warehouse_id] = true;

            return [
                'id' => $batch->id,
                'lot_number' => $batch->lot_number,
                'supplier' => $batch->supplier?->name,
                'initial_quantity' => $batch->initial_quantity,
                'remaining_quantity' => $batch->remaining_quantity,
                ...($canViewCosts ? ['unit_price' => $batch->unit_price] : []),
                'entry_date' => $batch->entry_date?->format('Y-m-d'),
                'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                'consumes_first' => $consumesFirst,
            ];
        })->values();

        $activeBatchCount = $activeBatchModels->count();
        $exhaustedBatchCount = $rawMaterial->inventoryBatches()
            ->exhausted()
            ->count();

        // El historial de lotes agotados solo se consulta cuando se abre esa pestaña.
        $exhaustedBatches = $batchesTab === 'exhausted'
            ? $rawMaterial->inventoryBatches()
                ->exhausted()
                ->select(
                    'id',
                    'raw_material_id',
                    'lot_number',
                    'supplier_id',
                    'initial_quantity',
                    'unit_price',
                    'entry_date',
                    'expiry_date',
                )
                ->with('supplier:id,name')
                ->orderByDesc('entry_date')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'batches_page')
                ->withQueryString()
                ->through(fn (InventoryBatch $batch): array => [
                    'id' => $batch->id,
                    'lot_number' => $batch->lot_number,
                    'supplier' => $batch->supplier?->name,
                    'initial_quantity' => $batch->initial_quantity,
                    ...($canViewCosts ? ['unit_price' => $batch->unit_price] : []),
                    'entry_date' => $batch->entry_date?->format('Y-m-d'),
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                ])
            : null;

        $totalAvailableQuantity = $this->calculator->sum(
            $activeBatchModels->pluck('remaining_quantity')->all(),
        );
        $hasAvailableStock = $activeBatchCount > 0;
        $hasActivity = $this->usage->hasActivity($rawMaterial);

        return Inertia::render('Inventory/RawMaterials/Show', [
            'returnTo' => $this->resolveReturnTo($request),
            // Array explícito: en materias primas el precio actual, el anterior y el de cada lote son costo
            // (docs/MATRIZ_RBAC.md, principio 1).
            'rawMaterial' => [
                'id' => $rawMaterial->id,
                'code' => $rawMaterial->code,
                ...($canViewCosts ? [
                    'current_price' => $rawMaterial->current_price,
                    'previous_price' => $rawMaterial->previous_price,
                ] : []),
                'minimum_stock' => $rawMaterial->minimum_stock,
                'alert_days_before_expiry' => $rawMaterial->alert_days_before_expiry,
                'tracks_inventory' => $rawMaterial->tracks_inventory,
                'is_controlled_substance' => $rawMaterial->is_controlled_substance,
                'is_active' => $rawMaterial->is_active,
                'category' => $rawMaterial->category ? [
                    'id' => $rawMaterial->category->id,
                    'name' => $rawMaterial->category->name,
                    'family_name' => $rawMaterial->category->family?->name,
                    'type' => $rawMaterial->category->type->value,
                    'type_label' => $rawMaterial->category->type->label(),
                ] : null,
                'unit_of_measure' => $rawMaterial->unitOfMeasure ? [
                    'id' => $rawMaterial->unitOfMeasure->id,
                    'name' => $rawMaterial->unitOfMeasure->name,
                    'symbol' => $rawMaterial->unitOfMeasure->symbol,
                ] : null,
            ],
            'batchesTab' => $batchesTab,
            'activeBatches' => $activeBatches,
            'exhaustedBatches' => $exhaustedBatches,
            'activeBatchCount' => $activeBatchCount,
            'exhaustedBatchCount' => $exhaustedBatchCount,
            'totalAvailableQuantity' => $totalAvailableQuantity,
            'hasAvailableStock' => $hasAvailableStock,
            'hasActivity' => $hasActivity,
            'can' => [
                'update' => Gate::allows('update', $rawMaterial),
                'delete' => $rawMaterial->is_active
                    ? Gate::allows('deactivate', $rawMaterial)
                    : $this->deletesPermanently($request->user(), $rawMaterial, $hasActivity),
                'reactivate' => Gate::allows('reactivate', $rawMaterial) && ! $rawMaterial->is_active,
                'deletePermanently' => $this->deletesPermanently($request->user(), $rawMaterial, $hasActivity),
                'viewCosts' => $canViewCosts,
            ],
        ]);
    }

    public function edit(RawMaterial $rawMaterial): Response
    {
        $this->authorize('update', $rawMaterial);

        $canUpdateCosts = Gate::allows(Permission::CostsUpdate->value);

        return Inertia::render('Inventory/RawMaterials/Edit', [
            // Solo los campos del formulario. El precio es costo: solo viaja a quien puede fijarlo, y el formulario solo lo
            // edita si la materia prima no controla inventario.
            'rawMaterial' => [
                'id' => $rawMaterial->id,
                'code' => $rawMaterial->code,
                'category_id' => $rawMaterial->category_id,
                'unit_of_measure_id' => $rawMaterial->unit_of_measure_id,
                'minimum_stock' => $rawMaterial->minimum_stock,
                'alert_days_before_expiry' => $rawMaterial->alert_days_before_expiry,
                'price_variation_threshold' => $rawMaterial->price_variation_threshold,
                'tracks_inventory' => $rawMaterial->tracks_inventory,
                'is_controlled_substance' => $rawMaterial->is_controlled_substance,
                ...($canUpdateCosts ? ['current_price' => $rawMaterial->current_price] : []),
                'is_active' => $rawMaterial->is_active,
            ],
            'can' => ['updateCosts' => $canUpdateCosts],
            // Activar el control de inventario las dejaría sin poder completarse hasta registrar compras: se confirma.
            'openProductionOrdersCount' => $canUpdateCosts && ! $rawMaterial->tracks_inventory ? $this->usage->openProductionOrdersCount($rawMaterial) : 0,
            // Con historial o en uso, la unidad no cambia (RM-04): UpdateRawMaterialRequest lo niega y la pantalla lo avisa.
            'unitLocked' => $this->usage->hasActivity($rawMaterial),
            'categories' => $this->categoryOptions(RawMaterialCategory::query()->selectable([$rawMaterial->category_id])),
            'units' => UnitOfMeasure::query()
                ->selectable([$rawMaterial->unit_of_measure_id])
                ->select('id', 'name', 'symbol')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(UpdateRawMaterialRequest $request, RawMaterial $rawMaterial): RedirectResponse
    {
        $this->authorize('update', $rawMaterial);

        $this->updateRawMaterial->execute($rawMaterial, Arr::except($request->validated(), 'confirm_tracking_change'));

        Inertia::flash('success', __('Materia prima actualizada exitosamente.'));

        return redirect()
            ->route('raw-materials.index');
    }

    /**
     * Remove or deactivate the specified raw material depending on activity and stock.
     */
    public function destroy(Request $request, RawMaterial $rawMaterial): RedirectResponse
    {
        $this->authorize('deactivate', $rawMaterial);

        $user = $request->user();

        return DB::transaction(function () use ($rawMaterial, $user): RedirectResponse {
            /** @var RawMaterial $lockedRawMaterial */
            $lockedRawMaterial = RawMaterial::query()
                ->lockForUpdate()
                ->findOrFail($rawMaterial->id);

            $hasActivity = $this->usage->hasActivity($lockedRawMaterial);

            // Misma decisión que muestran el listado y el detalle. Las claves foráneas siguen de red (docs/POLITICA_ELIMINACION.md
            // §4): si aun así tiene historial, el borrado no ocurre y se desactiva.
            if ($this->deletesPermanently($user, $lockedRawMaterial, $hasActivity) && $this->deleteUnused->execute($lockedRawMaterial)) {
                Inertia::flash('success', __('Materia prima eliminada físicamente exitosamente.'));

                return redirect()
                    ->route('raw-materials.index');
            }

            if (! $lockedRawMaterial->is_active) {
                Inertia::flash('error', $hasActivity
                    ? __('La materia prima tiene historial: no se puede eliminar y ya se encuentra inactiva.')
                    : __('La materia prima ya se encuentra inactiva.'));

                return back();
            }

            $hasAvailableBatches = $lockedRawMaterial->inventoryBatches()
                ->available()
                ->exists();

            if ($hasAvailableBatches) {
                Inertia::flash('error', __('No se puede desactivar ni eliminar la materia prima porque tiene lotes activos con stock disponible.'));

                return back();
            }

            $lockedRawMaterial->update(['is_active' => false]);

            Inertia::flash('success', __('Materia prima desactivada exitosamente (conserva historial).'));

            return redirect()
                ->route('raw-materials.index');
        }, attempts: 3);
    }

    public function reactivate(RawMaterial $rawMaterial): RedirectResponse
    {
        $this->authorize('reactivate', $rawMaterial);

        if ($rawMaterial->is_active) {
            Inertia::flash('error', __('La materia prima ya se encuentra activa.'));

            return back();
        }

        $rawMaterial->update(['is_active' => true]);

        Inertia::flash('success', __('Materia prima reactivada exitosamente.'));

        return redirect()
            ->route('raw-materials.index', ['status' => 'inactive']);
    }

    /**
     * Categorías para el selector, con el tipo de insumo que heredará la materia prima: el formulario lo muestra en
     * cuanto se elige la categoría.
     *
     * @param  Builder<RawMaterialCategory>  $query
     *                                               El formulario las agrupa por familia: van ordenadas por familia y luego por nombre (las sin familia, al final).
     * @return list<array{id: int, name: string, family_name: string|null, type: string, type_label: string}>
     */
    private function categoryOptions(Builder $query): array
    {
        // Se ordena en SQL, como el filtro de familia: así «Óxidos» queda con la O y no después de la Z.
        return $query
            ->leftJoin('raw_material_families', 'raw_material_families.id', '=', 'raw_material_categories.family_id')
            ->select('raw_material_categories.id', 'raw_material_categories.name', 'raw_material_categories.type', 'raw_material_categories.family_id')
            ->with('family:id,name')
            ->orderByRaw('raw_material_families.id IS NULL')
            ->orderBy('raw_material_families.name')
            ->orderBy('raw_material_categories.name')
            ->orderBy('raw_material_categories.id')
            ->get()
            ->map(fn (RawMaterialCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'family_name' => $category->family?->name,
                'type' => $category->type->value,
                'type_label' => $category->type->label(),
            ])
            ->all();
    }

    /**
     * Si «eliminar» borra la materia prima de verdad: exige raw_materials.delete y que nunca se haya usado. Si no,
     * destroy() la desactiva. Única fuente de esa decisión para el listado, el detalle y destroy().
     */
    private function deletesPermanently(?User $user, RawMaterial $rawMaterial, bool $hasActivity): bool
    {
        return $user !== null && Gate::forUser($user)->allows('delete', $rawMaterial) && ! $hasActivity;
    }
}
