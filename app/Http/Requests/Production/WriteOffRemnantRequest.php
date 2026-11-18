<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WriteOffRemnantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('writeOff', $this->route('remnant')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Queda en el historial: una baja sin explicación no dice qué pasó con el producto.
            'notes' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'notes' => 'motivo de la baja',
        ];
    }
}
