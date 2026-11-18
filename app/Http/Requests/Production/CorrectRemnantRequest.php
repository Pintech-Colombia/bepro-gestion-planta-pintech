<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\NumberType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CorrectRemnantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', $this->route('remnant')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Los kilos que se pesaron, no la diferencia. A 0 kg no se corrige: para eso está la baja.
            'quantity_kg' => ['bail', 'required', ...NumberType::Quantity->rules(), 'min:0.0001'],
            'notes' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity_kg' => 'kilos reales',
            'notes' => 'motivo de la corrección',
        ];
    }
}
