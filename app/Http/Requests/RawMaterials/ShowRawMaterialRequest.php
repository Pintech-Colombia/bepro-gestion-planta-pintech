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
            'batches' => ['nullable', 'string', 'in:active,exhausted'],
            'batches_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'batches' => 'pestaña de lotes',
            'batches_page' => 'página del historial de lotes',
        ];
    }

    /**
     * Pestaña activa de la sección de lotes en la ficha.
     */
    public function batchesTab(): string
    {
        $tab = $this->validated('batches');

        return is_string($tab) && $tab !== '' ? $tab : 'active';
    }
}
