<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\NumberType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBatchConsumptionRequest extends FormRequest
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
        return [
            'units' => ['bail', 'required', ...NumberType::Units->rules(), 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'units' => 'unidades',
        ];
    }
}
