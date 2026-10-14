<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class SaveDepartmentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_products') ?? false; }
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'business_id' => ['prohibited'], 'current_name' => ['prohibited'],
            'assigned_products_count' => ['prohibited'], 'deleted_at' => ['prohibited'], 'is_active' => ['prohibited']];
    }
}
