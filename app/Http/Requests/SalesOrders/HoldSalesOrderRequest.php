<?php

declare(strict_types=1);

namespace App\Http\Requests\SalesOrders;

use Illuminate\Foundation\Http\FormRequest;

class HoldSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hold', $this->route('sales_order')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'motivo',
        ];
    }
}
