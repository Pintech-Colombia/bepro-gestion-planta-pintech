<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ShowProductRequest extends FormRequest
{
    private const DEFAULT_SECTION = 'presentations';

    /** @var list<string> */
    private const ALLOWED_SECTIONS = [
        'presentations',
        'formulas',
        'quality',
    ];

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && ($this->user()?->can('view', $product) ?? false);
    }

    /**
     * Cualquier valor desconocido, demasiado largo o no escalar cae en la pestaña por defecto sin fallar la validación.
     */
    protected function prepareForValidation(): void
    {
        $section = $this->input('section');

        if (! is_string($section) || ! in_array($section, self::ALLOWED_SECTIONS, true)) {
            $this->merge(['section' => null]);
        }
    }

    /**
     * Sin `in:`: un valor desconocido no da error, abre la pestaña por defecto.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'section' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'section' => 'pestaña de la ficha',
        ];
    }

    /**
     * Pestaña activa de la ficha: `presentations`, `formulas` o `quality`. Cualquier otro valor, o `formulas` sin
     * permiso para ver fórmulas, abre Presentaciones.
     */
    public function section(bool $canViewFormulas): string
    {
        $section = $this->validated('section');

        return match ($section) {
            'quality' => 'quality',
            'formulas' => $canViewFormulas ? 'formulas' : self::DEFAULT_SECTION,
            default => self::DEFAULT_SECTION,
        };
    }
}
