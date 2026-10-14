<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class ListMovementsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('receive_stock') ?? false; }
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'batch_id' => ['sometimes', 'string', 'regex:/^bat_[0-9A-HJKMNP-TV-Z]{26}$/D']];
    }
}
