<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Formulas\ChangeFormulaActiveAction;
use App\Actions\Formulas\CreateFormulaAction;
use App\Actions\Formulas\DeleteFormulaAction;
use App\Actions\Formulas\SetPriceReferenceFormulaAction;
use App\Actions\Formulas\UpdateFormulaAction;
use App\Enums\FormulaLineCostingBasis;
use App\Enums\Permission;
use App\Filters\FormulaFilter;
use App\Http\Requests\Formulas\IndexFormulaRequest;
use App\Http\Requests\Formulas\StoreFormulaRequest;
use App\Http\Requests\Formulas\UpdateFormulaRequest;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\UnitOfMeasure;
use App\Services\Pricing\ProductLineTheoreticalCost;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FormulaController extends Controller
{
    public function __construct(
        private readonly DeleteFormulaAction $deleteFormula,
        private readonly CreateFormulaAction $createFormula,
        private readonly SetPriceReferenceFormulaAction $setPriceReference,
        private readonly ChangeFormulaActiveAction $changeActive,
        private readonly UpdateFormulaAction $updateFormula,
        private readonly ProductLineTheoreticalCost $productLineCost,
    ) {}

    public function index(IndexFormulaRequest $request): Response
    {
        $formulas = (new FormulaFilter($request))
            ->apply(Formula::query())
            ->with(['product:id,name', 'createdBy:id,name'])
            ->latest('id')
            ->paginate(15)
            ->onEachSide(1)
            ->withQueryString();

        return Inertia::render('Formulas/Index', [
            'formulas' => $formulas,
            'filters' => $request->validated(),
            'can' => [
                'create' => Gate::allows('create', Formula::class),
                'delete' => $request->user()?->can(Permission::FormulasDelete->value) ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Formula::class);

        return Inertia::render('Formulas/Create', [
            ...$this->formulaFormOptions(),
            'selectedProductId' => $request->input('product_id'),
            'returnTo' => $this->resolveReturnTo($request),
        ]);
    }

    public function store(StoreFormulaRequest $request): RedirectResponse
    {
        $this->authorize('create', Formula::class);

        $formula = $this->createFormula->execute($request->validated(), (int) $request->user()->id);

        $message = $formula->is_price_reference
            ? __('Fórmula v:version creada. Es la referencia de precio del producto.', ['version' => $formula->version])
            : __('Fórmula v:version creada y activa. El precio del producto sigue saliendo de la v:reference.', [
                'version' => $formula->version,
                'reference' => Formula::query()->where('product_id', $formula->product_id)->priceReference()->value('version'),
            ]);

        return $this->redirectWithContext($request, 'formulas.index', [], 'success', $message);
    }

    public function show(Request $request, Formula $formula): Response
    {
        $this->authorize('view', $formula);

        $formula->load([
            'product:id,name,unit_of_measure_id',
            'product.unitOfMeasure:id,name,symbol',
            'details.rawMaterial:id,code',
            'details.unitOfMeasure:id,name,symbol',
            'details.product',
            'details.costingVariant:id,presentation_label',
            'createdBy:id,name',
        ]);

        $canViewCosts = $request->user()?->can(Permission::CostsView->value) ?? false;

        return Inertia::render('Formulas/Show', [
            'formula' => [
                ...$formula->toArray(),
                // El producto de cada línea solo con id y nombre: su costo y su CIF viajan aparte, y solo con costs.view.
                'details' => $formula->details->map(fn (FormulaDetail $detail): array => [
                    ...$detail->only(['id', 'step_order', 'raw_material_id', 'product_id', 'quantity', 'unit_of_measure_id']),
                    'costing_basis' => $detail->costing_basis?->value,
                    // «Se costea como», ya armado: el texto de granel sale del enum, no se repite en la pantalla.
                    'costing_label' => $detail->isProductLine()
                        ? ($detail->costing_basis === FormulaLineCostingBasis::Presentation ? $detail->costingVariant?->presentation_label : $detail->costing_basis?->label())
                        : null,
                    'raw_material' => $detail->rawMaterial?->only(['id', 'code']),
                    'product' => $detail->product?->only(['id', 'name']),
                    'costing_variant' => $detail->costingVariant?->only(['id', 'presentation_label']),
                    'unit_of_measure' => $detail->unitOfMeasure?->only(['id', 'name', 'symbol']),
                ])->all(),
                'has_production_orders' => $formula->productionOrders()->exists(),
            ],
            // Costo teórico por kilo de cada línea de producto (P13), con su desglose o el motivo: solo con costs.view.
            'productLineCosts' => $canViewCosts ? $this->productLineCosts($formula) : [],
            'returnTo' => $this->resolveReturnTo($request),
            'can' => [
                'update' => Gate::allows('update', $formula),
                'activate' => Gate::allows('activate', $formula),
                'delete' => Gate::allows('delete', $formula),
                'viewProduct' => $request->user()?->can(Permission::ProductsView->value) ?? false,
                'viewCosts' => $canViewCosts,
            ],
        ]);
    }

    /**
     * @return array<int, array{cost_per_kg: string|null, bulk_per_kg: string|null, packaging_per_kg: string|null, density: string|null, density_source: string|null, density_order_number: string|null, reason: string|null}>
     */
    private function productLineCosts(Formula $formula): array
    {
        $costs = $this->productLineCost->forLines($formula->details);

        foreach ($costs as $lineId => $cost) {
            if ($cost['reason'] !== null) {
                $line = $formula->details->firstWhere('id', $lineId);
                $costs[$lineId]['reason'] = $this->productLineCost->reasonLabel($cost['reason'], (string) $line?->product?->name);
            }
        }

        return $costs;
    }

    public function edit(Formula $formula): Response|RedirectResponse
    {
        $this->authorize('update', $formula);

        // Mismas reglas y mensajes que al guardar (UpdateFormulaAction): usada en una OP, o producto inactivo (P9-13).
        if (($blocker = $this->updateFormula->blocker($formula)) !== null) {
            Inertia::flash('error', $blocker);

            return redirect()->route('formulas.show', $formula);
        }

        $formula->load([
            'product:id,name',
            'details:id,formula_id,raw_material_id,product_id,costing_basis,costing_variant_id,quantity,unit_of_measure_id,step_order',
        ]);

        return Inertia::render('Formulas/Edit', [
            'formula' => $formula,
            ...$this->formulaFormOptions(),
        ]);
    }

    public function update(UpdateFormulaRequest $request, Formula $formula): RedirectResponse
    {
        $this->authorize('update', $formula);

        try {
            $this->updateFormula->execute($formula, $request->validated());
        } catch (DomainException $exception) {
            Inertia::flash('error', $exception->getMessage());

            return redirect()->route('formulas.show', $formula);
        }

        Inertia::flash('success', 'Fórmula actualizada exitosamente.');

        return redirect()
            ->route('formulas.show', $formula);
    }

    public function destroy(Formula $formula): RedirectResponse
    {
        $this->authorize('delete', $formula);

        try {
            $deleted = $this->deleteFormula->execute($formula);
        } catch (DomainException $exception) {
            Inertia::flash('error', $exception->getMessage());

            return back();
        }

        if (! $deleted) {
            Inertia::flash('error', __('La fórmula tiene historial (órdenes de producción o costos calculados). Desactívala en su lugar.'));

            return back();
        }

        Inertia::flash('success', 'Fórmula eliminada exitosamente.');

        return redirect()->route('formulas.index');
    }

    /**
     * Activa esta fórmula sin tocar las demás: un producto puede tener varias activas (D-FOR1).
     */
    public function activate(Formula $formula): RedirectResponse
    {
        $this->authorize('activate', $formula);

        try {
            $formula = $this->changeActive->execute($formula, true);
        } catch (DomainException $exception) {
            Inertia::flash('error', $exception->getMessage());

            return back();
        }

        Inertia::flash('success', $formula->is_price_reference
            ? __('Fórmula v:version activada. Es la referencia de precio del producto.', ['version' => $formula->version])
            : __('Fórmula v:version activada.', ['version' => $formula->version]));

        return redirect()->route('formulas.show', $formula);
    }

    public function deactivate(Formula $formula): RedirectResponse
    {
        $this->authorize('activate', $formula);

        try {
            $this->changeActive->execute($formula, false);
        } catch (DomainException $exception) {
            Inertia::flash('error', $exception->getMessage());

            return back();
        }

        Inertia::flash('success', __('Fórmula v:version desactivada.', ['version' => $formula->version]));

        return redirect()->route('formulas.show', $formula);
    }

    /**
     * «Usar para el precio»: el producto pasa a costearse con esta fórmula.
     */
    public function priceReference(Formula $formula): RedirectResponse
    {
        $this->authorize('activate', $formula);

        try {
            $changed = $this->setPriceReference->execute($formula);
        } catch (DomainException $exception) {
            Inertia::flash('error', $exception->getMessage());

            return back();
        }

        Inertia::flash('success', $changed
            ? __('El precio del producto ahora sale de la fórmula v:version.', ['version' => $formula->version])
            : __('La fórmula v:version ya era la referencia de precio.', ['version' => $formula->version]));

        return redirect()->route('formulas.show', $formula);
    }

    /**
     * @return array<string, mixed>
     */
    private function formulaFormOptions(): array
    {
        return [
            'products' => Product::query()
                ->where('is_active', true)
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
            // Solo tipo Químico, como exige StoreFormulaRequest.
            'rawMaterials' => RawMaterial::query()
                ->where('is_active', true)
                ->usableInFormulas()
                ->select('id', 'code')
                ->orderBy('code')
                ->get(),
            // Un producto como ingrediente (PT consumible): activo, con sus presentaciones activas para «Se costea como» (P14).
            // El formulario quita el producto de la propia fórmula.
            'ingredientProducts' => Product::query()
                ->where('is_active', true)
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('presentation_value')->select('id', 'product_id', 'presentation_label')])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'variants' => $product->variants->map(fn ($variant): array => ['id' => $variant->id, 'label' => $variant->presentation_label])->all(),
                ])
                ->all(),
            // StoreFormulaRequest solo acepta unidades activas: el desplegable ofrece las mismas. `is_mass`: un producto
            // solo va en una unidad de peso.
            'units' => UnitOfMeasure::query()
                ->selectable()
                ->orderBy('name')
                ->get(['id', 'name', 'symbol', 'to_kg_conversion'])
                ->map(fn (UnitOfMeasure $unit): array => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'symbol' => $unit->symbol,
                    'is_mass' => $unit->to_kg_conversion !== null,
                ])
                ->all(),
        ];
    }
}
