<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class StockAlertsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('receive_stock') ?? false; }
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'days' => ['sometimes', 'integer', 'min:0', 'max:365']];
    }
}
