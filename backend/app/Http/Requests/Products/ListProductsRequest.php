<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ((! $this->filled('low_stock') && ! $this->filled('expiring_in')) || $this->user()->can('receive_stock'));
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[!-~]+$/D'],
            'low_stock' => ['sometimes', 'boolean'], 'expiring_in' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'department_id' => ['sometimes', 'nullable', 'string', 'regex:/^dep_[0-9A-HJKMNP-TV-Z]{26}$/D'],
        ];
    }
}
