<?php

declare(strict_types=1);

namespace App\Http\Requests\RawMaterials;

use App\Models\RawMaterial;
use Illuminate\Foundation\Http\FormRequest;

class ShowRawMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rawMaterial = $this->route('raw_material');

        return $rawMaterial instanceof RawMaterial
            && ($this->user()?->can('view', $rawMaterial) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lotes' => ['nullable', 'string', 'in:existencia,historial'],
            'lotes_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lotes' => 'pestaña de lotes',
            'lotes_page' => 'página del historial de lotes',
        ];
    }

    /**
     * Pestaña activa de la sección de lotes en la ficha.
     */
    public function batchesTab(): string
    {
        $tab = $this->validated('lotes');

        return is_string($tab) && $tab !== '' ? $tab : 'existencia';
    }
}
