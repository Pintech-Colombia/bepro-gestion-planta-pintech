<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogs;

use App\Enums\NumberType;
use App\Enums\TextFormat;
use App\Http\Requests\Concerns\NormalizesText;
use App\Models\RawMaterialFamily;
use App\Rules\UniqueIgnoringCase;
use Illuminate\Foundation\Http\FormRequest;

class StoreRawMaterialFamilyRequest extends FormRequest
{
    use NormalizesText;

    public function authorize(): bool
    {
        return $this->user()?->can('create', RawMaterialFamily::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:100', new UniqueIgnoringCase('raw_material_families', 'name')],
            ...$this->sharedRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:1000'],
            // Vacío = el general (`config/production.php`). Hasta 100 %: el doble de lo planeado ya no es tolerancia.
            'consumption_tolerance_percentage' => ['nullable', ...NumberType::Percentage->rules('100'), 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Si la petición no trae el estado: al crear, activa; al editar, el que ya tenía.
     */
    protected function defaultIsActive(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'is_active' => $this->boolean('is_active', $this->defaultIsActive()),
            ...$this->normalizedText(['name' => TextFormat::Name]),
        ];

        foreach (['description', 'consumption_tolerance_percentage'] as $field) {
            if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }
}
