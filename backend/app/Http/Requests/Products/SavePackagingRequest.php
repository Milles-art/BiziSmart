<?php

namespace App\Http\Requests\Products;

use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;

class SavePackagingRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_products') ?? false; }
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        return [
            'name' => [$required, 'string', 'max:160'],
            'base_qty' => ['bail', $required, 'string', 'max:32', function ($attribute, $value, $fail) {
                try {
                    if (Quantity::parse($value, weighted: $this->route('product')->quantity_type === 'weight') <= 0) { $fail('A pack must contain a positive base quantity.'); }
                } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); }
            }],
            'selling_price' => [$required, function ($attribute, $value, $fail) {
                try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); }
            }],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[!-~]+$/D'],
            'is_active' => ['sometimes', 'boolean'],
            'business_id' => ['prohibited'], 'product_id' => ['prohibited'], 'conversion_atoms' => ['prohibited'],
            'version' => ['prohibited'], 'family_id' => ['prohibited'], 'stock' => ['prohibited'], 'archived_at' => ['prohibited'],
        ];
    }
}
