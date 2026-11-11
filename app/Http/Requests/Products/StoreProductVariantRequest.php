<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Enums\NumberType;
use App\Enums\RawMaterialType;
use App\Enums\TextFormat;
use App\Http\Requests\Concerns\CatalogSelectionRules;
use App\Http\Requests\Concerns\NormalizesText;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Rules\RawMaterialOfType;
use App\Rules\UniqueIgnoringCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear y editar una presentación validan igual (`UpdateProductVariantRequest` hereda de esta): al editar, la variante
 * actual sale de la ruta y conserva lo suyo (su código, su presentación y la unidad, el envase o la etiqueta aunque se
 * hayan desactivado); al crear no hay variante actual y todo debe estar activo y libre.
 */
class StoreProductVariantRequest extends FormRequest
{
    use CatalogSelectionRules;
    use NormalizesText;

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && ($this->user()?->can('manageVariants', $product) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->currentVariant();
        /** @var Product $product `authorize` ya exige el producto de la ruta. */
        $product = $this->route('product');

        return [
            'code' => ['bail', 'required', 'string', 'max:80', ...TextFormat::Code->rules(), Rule::unique('product_variants', 'code')->ignore($current?->id)],
            'unit_of_measure_id' => $this->activeOrCurrentRules('unit_of_measures', 'unit_of_measure_id', $current?->unit_of_measure_id),
            'presentation_value' => ['bail', 'nullable', ...NumberType::Quantity->rules(ProductVariant::MAX_PRESENTATION_VALUE), 'gt:0'],
            // La presentación identifica la variante: única dentro del producto sin distinguir mayúsculas, contando las
            // desactivadas (esas se reactivan, no se repiten). La cuida también el índice de la base.
            'presentation_label' => [
                'bail',
                'required',
                'string',
                'max:50',
                new UniqueIgnoringCase('product_variants', 'presentation_label', $current?->id, ['product_id' => $product->id]),
            ],
            // B40: el envase debe ser una materia prima de tipo Envase.
            'package_raw_material_id' => $this->rawMaterialRules('package_raw_material_id', RawMaterialType::Container, $current?->package_raw_material_id),
            // 3.7: la etiqueta habitual debe ser una materia prima de tipo Etiqueta.
            'label_raw_material_id' => $this->rawMaterialRules('label_raw_material_id', RawMaterialType::Label, $current?->label_raw_material_id),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'presentation_label.'.UniqueIgnoringCase::class => 'Este producto ya tiene la presentación «:input».',
        ];
    }

    /** La variante que se edita; `null` al crear. */
    protected function currentVariant(): ?ProductVariant
    {
        $variant = $this->route('variant');

        return $variant instanceof ProductVariant ? $variant : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizedText(['code' => TextFormat::Code, 'presentation_label' => TextFormat::Name]));

        foreach (['presentation_value', 'package_raw_material_id', 'label_raw_material_id'] as $key) {
            if ($this->has($key) && ($this->input($key) === '' || $this->input($key) === null)) {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * Materia prima del tipo pedido. Debe estar activa, salvo que sea la que la variante ya tiene: una inactiva solo la
     * conserva la presentación que ya la usa.
     *
     * @return list<mixed>
     */
    private function rawMaterialRules(string $field, RawMaterialType $type, ?int $currentId): array
    {
        $chosenId = $this->input($field);

        return [
            'nullable',
            'integer',
            Rule::exists('raw_materials', 'id')->when(
                $chosenId && (int) $chosenId !== (int) $currentId,
                fn ($rule) => $rule->where('is_active', true)
            ),
            new RawMaterialOfType($type),
        ];
    }
}
