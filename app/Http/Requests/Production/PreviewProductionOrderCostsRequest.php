<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\NumberType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewProductionOrderCostsRequest extends FormRequest
{
    use ProductionConsumptionRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('previewCosts', $this->route('production_order')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            $this->consumptionRules(),
            [
                'remnant_quantity_kg' => ['bail', 'nullable', ...NumberType::Quantity->rules(), 'min:0'],
                // Opcional aquí: sin él, la vista previa calcula sin sobrante y no da error.
                'density_kg_per_gallon' => ['bail', 'nullable', ...NumberType::Density->rules(), 'min:0.0001'],
                // Lo que quedó en cada envase abierto, tal como se escribe: el costo de la pantalla es el de completar. Sin la
                // lista, lo guardado. Que no pase de una unidad lo revisa completar; aquí solo la forma.
                'batch_consumptions' => ['sometimes', 'array'],
                'batch_consumptions.*.id' => [
                    'bail',
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('production_order_batch_consumptions', 'id')->where('production_order_id', $this->resolveProductionOrderId() ?? 0),
                ],
                'batch_consumptions.*.has_leftover' => ['sometimes', 'boolean'],
                'batch_consumptions.*.leftover_kg' => ['bail', 'nullable', ...NumberType::Quantity->rules(), 'min:0'],
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(
            $this->consumptionAttributes(),
            [
                'remnant_quantity_kg' => 'sobrante pesado (kg)',
                'density_kg_per_gallon' => 'peso por galón (kg/gal)',
            ]
        );
    }
}
