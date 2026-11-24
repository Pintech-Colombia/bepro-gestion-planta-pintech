<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogs;

use App\Models\RawMaterialFamily;
use App\Rules\UniqueIgnoringCase;

class UpdateRawMaterialFamilyRequest extends StoreRawMaterialFamilyRequest
{
    public function authorize(): bool
    {
        $family = $this->route('raw_material_family');

        return $family instanceof RawMaterialFamily
            && ($this->user()?->can('update', $family) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $family = $this->route('raw_material_family');

        return [
            'name' => ['bail', 'required', 'string', 'max:100', new UniqueIgnoringCase('raw_material_families', 'name', $family?->id)],
            ...$this->sharedRules(),
        ];
    }

    protected function defaultIsActive(): bool
    {
        $family = $this->route('raw_material_family');

        return $family instanceof RawMaterialFamily ? $family->is_active : true;
    }
}
