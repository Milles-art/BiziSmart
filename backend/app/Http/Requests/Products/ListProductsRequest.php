<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[!-~]+$/D'],
            'low_stock' => ['prohibited'], 'expiring_in' => ['prohibited'], 'department_id' => ['prohibited'],
        ];
    }
}
