<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Actualiza el margen de venta de un producto (única fuente de escritura).
 */
class UpdateProductSalesMarginAction
{
    public function execute(Product $product, ?string $salesMargin): Product
    {
        return DB::transaction(function () use ($product, $salesMargin): Product {
            $product->update([
                'sales_margin' => $salesMargin,
            ]);

            return $product->refresh();
        });
    }
}
