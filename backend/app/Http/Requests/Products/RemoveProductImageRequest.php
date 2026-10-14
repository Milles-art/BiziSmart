<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class RemoveProductImageRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_products') ?? false; }
    public function rules(): array
    {
        return ['expected_revision' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'business_id' => ['prohibited'], 'path' => ['prohibited'], 'image_path' => ['prohibited']];
    }
}
