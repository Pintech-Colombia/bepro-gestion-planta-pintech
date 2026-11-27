<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\UpdateProductSalesMarginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\IndexPriceListRequest;
use App\Http\Requests\Pricing\UpdateProductMarginRequest;
use App\Models\Product;
use App\Services\PriceListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function __construct(
        private readonly PriceListService $priceListService,
        private readonly UpdateProductSalesMarginAction $updateProductSalesMarginAction,
    ) {}

    /**
     * Display the price list for admin and comercial roles.
     */
    public function index(IndexPriceListRequest $request): Response
    {
        return Inertia::render('Prices/Index', $this->priceListService->buildList(
            $request->user(),
            $request,
        ));
    }

    /**
     * Guarda el margen de venta de un producto desde la lista de precios.
     */
    public function updateMargin(UpdateProductMarginRequest $request, Product $product): RedirectResponse
    {
        $this->updateProductSalesMarginAction->execute($product, $request->salesMargin());

        Inertia::flash('success', 'Margen de venta actualizado correctamente.');

        return back();
    }

    /**
     * Calcula la vista previa de precios de lista en pesos enteros para un margen dado.
     */
    public function previewMargin(UpdateProductMarginRequest $request, Product $product): JsonResponse
    {
        return response()->json(
            $this->priceListService->previewMargin($product, $request->salesMargin()),
        );
    }
}
