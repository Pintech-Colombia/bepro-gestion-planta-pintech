<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\NumberType;
use App\Models\ProductionOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRemnantConsumptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('planRemnant', $this->route('production_order')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $order = $this->route('production_order');

        return [
            'remnant_id' => ['required', 'integer', 'exists:production_remnants,id'],
            'quantity_kg' => ['bail', 'required', ...NumberType::Quantity->rules(), 'min:0.0001'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Opcional: la línea de «Productos a usar» a la que va. Sin ella, la del producto del saldo.
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
            'quantity_kg' => 'kilos a usar',
            'production_order_product_line_id' => 'línea de producto',
        ];
    }
}
