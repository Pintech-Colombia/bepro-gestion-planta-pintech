<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Rules\UniqueIgnoringCase;

/**
 * La presentación identifica la variante dentro de su producto: obligatoria y única en ese producto sin distinguir
 * mayúsculas («Galón» y «galón» son la misma), contando también las desactivadas (esas se reactivan, no se repiten).
 * La cuida además el índice `product_variants_product_presentation_lower_unique`.
 */
trait VariantPresentationRules
{
    /**
     * @param  int|null  $ignoreId  La variante que se edita; `null` al crear.
     * @return list<mixed>
     */
    protected function presentationLabelRules(Product $product, ?int $ignoreId = null): array
    {
        return [
            'bail',
            'required',
            'string',
            'max:50',
            new UniqueIgnoringCase('product_variants', 'presentation_label', $ignoreId, ['product_id' => $product->id]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function presentationLabelMessages(): array
    {
        return [
            'presentation_label.'.UniqueIgnoringCase::class => 'Este producto ya tiene la presentación «:input».',
        ];
    }
}
