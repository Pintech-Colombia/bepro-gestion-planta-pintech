<?php

declare(strict_types=1);

namespace App\Http\Requests\SalesOrders;

use App\Actions\SalesOrders\SplitSalesOrderRemainder;
use App\Enums\NumberType;
use App\Http\Requests\Concerns\ReadsIdInput;
use App\Models\SalesOrder;
use App\Support\NumberFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApproveSalesOrderRequest extends FormRequest
{
    use ReadsIdInput;

    /** @var array<int, int>|null Cantidad pedida por id de línea, leída una vez. */
    private ?array $orderLines = null;

    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('sales_order')) ?? false;
    }

    /**
     * La nota es opcional al aprobar y obligatoria si la aprobación es parcial: es el motivo del pedido retenido con el
     * resto. Parcial dice, por cada línea del pedido, cuánto sale ahora (`items`); si no es parcial, `items` se ignora.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $notPartial = Rule::excludeIf(fn (): bool => ! $this->boolean('is_partial'));

        return [
            'is_partial' => ['sometimes', 'boolean'],
            'note' => ['nullable', Rule::requiredIf(fn (): bool => $this->boolean('is_partial')), 'string', 'max:2000'],
            'items' => [$notPartial, 'required', 'array', 'min:1'],
            'items.*.id' => [$notPartial, 'bail', 'required', 'integer', 'distinct', Rule::in(array_keys($this->orderLines()))],
            'items.*.approved_quantity' => [
                $notPartial,
                'bail',
                'required',
                ...NumberType::Units->rules(),
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $index = explode('.', $attribute)[1] ?? '';
                    $ordered = $this->orderLines()[$this->idInput("items.{$index}.id")] ?? null;

                    // Sin línea válida, el error es del id.
                    if ($ordered !== null && (int) $value > $ordered) {
                        $fail('No puedes aprobar más de lo pedido ('.NumberFormat::units((string) $ordered).').');
                    }
                },
            ],
        ];
    }

    /**
     * Reglas del conjunto, en `items`: una entrada por cada línea, al menos una unidad aprobada y al menos una que pase
     * al resto. Solo cuando cada línea ya pasó sus reglas.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('is_partial') || $this->hasItemErrors($validator)) {
                    return;
                }

                $approved = $this->approvedQuantities();
                $lines = $this->orderLines();

                if (count($approved) !== count($lines)) {
                    $validator->errors()->add('items', SplitSalesOrderRemainder::LINES_MISMATCH);

                    return;
                }

                $approvedTotal = array_sum($approved);
                $orderedTotal = array_sum($lines);

                if ($approvedTotal === 0) {
                    $validator->errors()->add('items', SplitSalesOrderRemainder::NOTHING_APPROVED);
                } elseif ($approvedTotal === $orderedTotal) {
                    $validator->errors()->add('items', SplitSalesOrderRemainder::NOTHING_LEFT);
                }
            },
        ];
    }

    /**
     * Unidades que salen ahora por id de línea, en enteros. Vacío si la aprobación no es parcial.
     *
     * @return array<int, int>
     */
    public function approvedQuantities(): array
    {
        if (! $this->boolean('is_partial')) {
            return [];
        }

        $quantities = [];

        foreach ((array) $this->input('items', []) as $item) {
            $quantities[(int) ($item['id'] ?? 0)] = (int) ($item['approved_quantity'] ?? 0);
        }

        return $quantities;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_partial' => 'aprobación parcial',
            'note' => 'nota',
            'items' => 'productos',
            'items.*.id' => 'producto',
            'items.*.approved_quantity' => 'aprobar ahora',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Escribe la nota: es el motivo del pedido retenido con lo que no se aprueba.',
            'items.required' => SplitSalesOrderRemainder::LINES_MISMATCH,
            'items.*.id.in' => 'Ese producto no es de este pedido.',
            'items.*.id.distinct' => 'Ese producto está repetido.',
            'items.*.approved_quantity.required' => 'Indica cuánto se aprueba ahora (0 si no sale).',
            'items.*.approved_quantity.min' => 'La cantidad no puede ser negativa.',
        ];
    }

    private function hasItemErrors(Validator $validator): bool
    {
        foreach (array_keys($validator->errors()->messages()) as $key) {
            if ($key === 'items' || str_starts_with($key, 'items.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function orderLines(): array
    {
        if ($this->orderLines !== null) {
            return $this->orderLines;
        }

        $order = $this->route('sales_order');

        return $this->orderLines = $order instanceof SalesOrder
            ? $order->items()->pluck('quantity', 'id')->map(fn (mixed $quantity): int => (int) $quantity)->all()
            : [];
    }
}
