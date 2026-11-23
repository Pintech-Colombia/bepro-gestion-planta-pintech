<?php

declare(strict_types=1);

namespace App\Http\Requests\Quotations;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuotationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quotation = $this->route('quotation');

        return $quotation instanceof Quotation
            && ($this->user()?->can('updateStatus', $quotation) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->route('quotation');

        $validTransitions = array_map(
            fn (QuotationStatus $status): string => $status->value,
            $quotation->status->nextTransitions()
        );

        return [
            'status' => [
                'required',
                Rule::enum(QuotationStatus::class),
                function (string $attribute, mixed $value, \Closure $fail) use ($validTransitions): void {
                    if (! in_array($value, $validTransitions, true)) {
                        $fail(__('La transición de estado no es válida.'));
                    }
                },
            ],
        ];
    }
}
