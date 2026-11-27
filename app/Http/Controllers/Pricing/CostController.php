<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pricing;

use App\Enums\Permission;
use App\Filters\CostFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\IndexCostRequest;
use App\Models\Product;
use App\Services\VariantSalesPriceService;
use Inertia\Inertia;
use Inertia\Response;

class CostController extends Controller
{
    public function __construct(
        private readonly VariantSalesPriceService $salesPriceService,
    ) {}

    /**
     * Display the costs dashboard for admin (read-only).
     */
    public function index(IndexCostRequest $request): Response
    {
        $products = (new CostFilter($request))
            ->apply(Product::query())
            ->select([
                'id',
                'name',
                'current_cost',
                'cif_percentage',
                'current_price',
                'sales_margin',
            ])
            ->active()
            ->orderBy('name')
            ->paginate(15)
            ->onEachSide(1)
            ->withQueryString()
            ->through(function (Product $product): array {
                $salesPrice = $this->salesPriceService->resolveForProduct($product);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'current_cost' => $product->current_cost,
                    'cif_percentage' => $product->cif_percentage,
                    'current_price' => $product->current_price,
                    'sales_margin' => $product->sales_margin,
                    'sales_price' => $salesPrice !== null ? (int) $salesPrice : null,
                ];
            });

        $user = $request->user();

        return Inertia::render('Costs/Index', [
            'products' => $products,
            'can' => [
                'update_margin' => $user?->can(Permission::CostsUpdate->value) ?? false,
                'view_prices' => $user?->can(Permission::PriceListsView->value) ?? false,
            ],
            'filters' => $request->validated(),
        ]);
    }
}
