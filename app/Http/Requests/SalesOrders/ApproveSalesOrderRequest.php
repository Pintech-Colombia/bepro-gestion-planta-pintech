<?php

declare(strict_types=1);

namespace App\Http\Requests\SalesOrders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('sales_order')) ?? false;
    }

    /**
     * La nota es opcional al aprobar y obligatoria si la aprobación es parcial: dice qué se aprobó.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_partial' => ['sometimes', 'boolean'],
            'note' => ['nullable', Rule::requiredIf(fn (): bool => $this->boolean('is_partial')), 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_partial' => 'aprobación parcial',
            'note' => 'nota',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Escribe en la nota qué se aprueba: la aprobación es parcial.',
        ];
    }
}
