<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use App\Enums\NumberType;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Margen de venta de un producto (0 a 99,99 %, 2 decimales). Se usa tanto para guardar como para la vista previa.
 */
class UpdateProductMarginRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && ($this->user()?->can('updateCost', $product) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Margen menor que 100 % (el precio es costo ÷ (1 − margen)), con 2 decimales.
            'sales_margin' => ['bail', 'nullable', ...NumberType::Percentage->rules('99.99'), 'min:0'],
        ];
    }

    /**
     * Margen validado (decimal en texto) o `null` para borrarlo.
     */
    public function salesMargin(): ?string
    {
        $margin = $this->validated('sales_margin');

        return $margin === null || $margin === '' ? null : (string) $margin;
    }
}
