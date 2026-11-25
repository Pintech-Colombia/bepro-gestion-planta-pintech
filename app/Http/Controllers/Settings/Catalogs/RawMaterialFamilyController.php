<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Catalogs;

use App\Actions\Shared\DeleteUnusedRecordAction;
use App\Filters\RawMaterialFamilyFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogs\IndexRawMaterialFamilyRequest;
use App\Http\Requests\Catalogs\StoreRawMaterialFamilyRequest;
use App\Http\Requests\Catalogs\UpdateRawMaterialFamilyRequest;
use App\Models\RawMaterialFamily;
use App\Services\Production\ProductionOrderConsumptionCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de familias de materia prima, en Configuración → Catálogos. Cada categoría pertenece a una familia.
 */
class RawMaterialFamilyController extends Controller
{
    public function __construct(
        private readonly DeleteUnusedRecordAction $deleteUnused,
        private readonly ProductionOrderConsumptionCheck $consumptionCheck,
    ) {}

    public function index(IndexRawMaterialFamilyRequest $request): Response
    {
        $families = (new RawMaterialFamilyFilter($request))
            ->apply(RawMaterialFamily::query()->withCount('categories'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->onEachSide(1)
            ->withQueryString()
            ->through(fn (RawMaterialFamily $family): array => [
                ...$this->familyData($family),
                'can' => [
                    'update' => Gate::allows('update', $family),
                    'delete' => Gate::allows('delete', $family),
                ],
            ]);

        return Inertia::render('Settings/Catalogs/RawMaterialFamilies/Index', [
            'families' => $families,
            'generalTolerance' => $this->consumptionCheck->currentTolerancePercentage(),
            'filters' => $request->validated(),
            'can' => [
                'create' => Gate::allows('create', RawMaterialFamily::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RawMaterialFamily::class);

        return Inertia::render('Settings/Catalogs/RawMaterialFamilies/Create', [
            'generalTolerance' => $this->consumptionCheck->currentTolerancePercentage(),
        ]);
    }

    public function store(StoreRawMaterialFamilyRequest $request): RedirectResponse
    {
        $this->authorize('create', RawMaterialFamily::class);

        RawMaterialFamily::create($request->validated());

        Inertia::flash('success', __('Familia de materia prima registrada exitosamente.'));

        return redirect()
            ->route('catalogs.raw-material-families.index');
    }

    public function edit(RawMaterialFamily $rawMaterialFamily): Response
    {
        $this->authorize('update', $rawMaterialFamily);

        $rawMaterialFamily->loadCount('categories');

        return Inertia::render('Settings/Catalogs/RawMaterialFamilies/Edit', [
            'family' => $this->familyData($rawMaterialFamily),
            'generalTolerance' => $this->consumptionCheck->currentTolerancePercentage(),
        ]);
    }

    public function update(UpdateRawMaterialFamilyRequest $request, RawMaterialFamily $rawMaterialFamily): RedirectResponse
    {
        $this->authorize('update', $rawMaterialFamily);

        $rawMaterialFamily->update($request->validated());

        Inertia::flash('success', __('Familia de materia prima actualizada exitosamente.'));

        return redirect()
            ->route('catalogs.raw-material-families.index');
    }

    public function destroy(RawMaterialFamily $rawMaterialFamily): RedirectResponse
    {
        $this->authorize('delete', $rawMaterialFamily);

        // Solo si nunca se usó (docs/POLITICA_ELIMINACION.md): la clave foránea RESTRICT decide.
        if (! $this->deleteUnused->execute($rawMaterialFamily)) {
            Inertia::flash('error', __('La familia tiene categorías. Desactívala en su lugar.'));

            return back();
        }

        Inertia::flash('success', __('Familia de materia prima eliminada exitosamente.'));

        return redirect()
            ->route('catalogs.raw-material-families.index');
    }

    /**
     * Requiere el conteo `categories_count` cargado.
     *
     * @return array<string, mixed>
     */
    private function familyData(RawMaterialFamily $family): array
    {
        return [
            'id' => $family->id,
            'name' => $family->name,
            'description' => $family->description,
            'consumption_tolerance_percentage' => $family->consumption_tolerance_percentage,
            'is_active' => $family->is_active,
            'categories_count' => (int) $family->categories_count,
        ];
    }
}
