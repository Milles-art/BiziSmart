<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class SaveProductImageRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_products') ?? false; }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:2048'],
            'expected_revision' => ['present', 'nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'business_id' => ['prohibited'], 'path' => ['prohibited'], 'image_path' => ['prohibited'],
        ];
    }
}
