<?php

namespace App\Http\Requests\Suppliers;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseSuggestionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasRole('owner') ?? false; }
    public function rules(): array
    {
        return ['business_id' => ['prohibited'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
