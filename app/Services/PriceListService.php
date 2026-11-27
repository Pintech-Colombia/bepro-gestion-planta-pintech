<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Filters\PriceListFilter;
use App\Http\Requests\Pricing\IndexPriceListRequest;
use App\Models\Product;
use App\Models\User;

class PriceListService
{
    public function __construct(
        private readonly VariantSalesPriceService $salesPriceService,
        private readonly FinishedInventoryQueryService $finishedInventoryQueryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildList(User $user, IndexPriceListRequest $request): array
    {
        $canViewCosts = $user->can(Permission::CostsView->value);
        $canUpdateMargin = $user->can(Permission::CostsUpdate->value);

        $baseQuery = Product::query()
            ->select([
                'id',
                'name',
                'current_cost',
                'cif_percentage',
                'current_price',
                'sales_margin',
            ])
            ->active()
            ->with([
                'variants' => function ($query): void {
                    $query->select([
                        'id',
                        'product_id',
                        'code',
                        'presentation_label',
                        'presentation_value',
                        'current_price',
                    ])
                        ->where('is_active', true)
                        ->orderedByPresentation();
                },
            ]);

        $products = (new PriceListFilter($request))
            ->apply($baseQuery)
            ->orderBy('name')
            ->paginate(15)
            ->onEachSide(1)
            ->withQueryString();

        $variantIds = $products->getCollection()
            ->flatMap(fn (Product $p) => $p->variants->pluck('id'))
            ->all();

        $variantStockTotals = $this->finishedInventoryQueryService->sumQuantityByVariant($user, $variantIds);

        $products->through(function (Product $product) use ($canViewCosts, $variantStockTotals) {
            $resolvedProductPrice = $this->salesPriceService->resolveForProduct($product);
            $productSalesPrice = $resolvedProductPrice !== null ? (int) $resolvedProductPrice : null;

            $productData = [
                'id' => $product->id,
                'name' => $product->name,
                'sales_price' => $productSalesPrice,
            ];

            // Con el precio de venta y el margen se despeja el precio interno: el margen también es costo.
            if ($canViewCosts) {
                $productData = array_merge($productData, [
                    'sales_margin' => $product->sales_margin,
                    'current_cost' => $product->current_cost,
                    'cif_percentage' => $product->cif_percentage,
                    'current_price' => $product->current_price,
                ]);
            }

            $variants = $product->variants->map(function ($variant) use ($canViewCosts, $variantStockTotals) {
                $resolvedVariantPrice = $this->salesPriceService->resolveForVariant($variant);
                $variantSalesPrice = $resolvedVariantPrice !== null ? (int) $resolvedVariantPrice : null;
                $availableStock = $variantStockTotals->get($variant->id);

                $variantData = [
                    'id' => $variant->id,
                    'code' => $variant->code,
                    'presentation_label' => $variant->presentation_label,
                    'presentation_value' => $variant->presentation_value,
                    'sales_price' => $variantSalesPrice,
                    'available_stock' => $availableStock !== null ? (float) $availableStock : 0.0,
                ];

                if ($canViewCosts) {
                    $variantData = array_merge($variantData, [
                        'current_price' => $variant->current_price,
                    ]);
                }

                return $variantData;
            });

            $productData['variants'] = $variants;

            return $productData;
        });

        return [
            'products' => $products,
            'can' => [
                'view_costs' => $canViewCosts,
                'view_prices' => true,
                'update_margin' => $canUpdateMargin,
            ],
            'filters' => $request->validated(),
        ];
    }

    /**
     * Vista previa de precios de lista en pesos enteros para un margen dado, sin guardar.
     *
     * @return array{
     *     product_sales_price: int|null,
     *     variants: array<int, array{id: int, sales_price: int|null}>
     * }
     */
    public function previewMargin(Product $product, ?string $salesMargin): array
    {
        $productPrice = $product->current_price !== null
            ? $this->salesPriceService->applySalesMargin($product->current_price, $salesMargin)
            : null;

        $variants = $product->variants()
            ->select(['id', 'product_id', 'current_price'])
            ->where('is_active', true)
            ->orderedByPresentation()
            ->get()
            ->map(function ($variant) use ($salesMargin): array {
                $price = $variant->current_price !== null
                    ? $this->salesPriceService->applySalesMargin($variant->current_price, $salesMargin)
                    : null;

                return [
                    'id' => (int) $variant->id,
                    'sales_price' => $price !== null ? (int) $price : null,
                ];
            })
            ->values()
            ->all();

        return [
            'product_sales_price' => $productPrice !== null ? (int) $productPrice : null,
            'variants' => $variants,
        ];
    }
}
