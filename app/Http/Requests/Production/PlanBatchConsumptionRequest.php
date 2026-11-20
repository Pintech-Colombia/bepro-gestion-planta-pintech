<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\NumberType;
use App\Models\ProductionOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanBatchConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('planRemnant', $this->route('production_order')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $order = $this->route('production_order');

        return [
            'finished_product_batch_id' => ['bail', 'required', 'integer', 'exists:finished_product_batches,id'],
            // Unidades enteras: se sacan envases completos de bodega.
            'units' => ['bail', 'required', ...NumberType::Units->rules(), 'min:1'],
            // Opcional: la línea a la que va (si la orden lleva el producto en dos pasos). Sin ella, la del producto.
            'production_order_product_line_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('production_order_product_lines', 'id')
                    ->where('production_order_id', $order instanceof ProductionOrder ? $order->id : 0),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'finished_product_batch_id' => 'lote',
            'units' => 'unidades',
            'production_order_product_line_id' => 'línea de producto',
        ];
    }
}
