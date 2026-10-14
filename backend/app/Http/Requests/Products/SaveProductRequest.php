<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_products') ?? false; }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $product = $this->route('product');
        $type = $this->input('quantity_type', $product instanceof Product ? $product->quantity_type : 'counted');
        $money = function ($attribute, $value, $fail) {
            try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); }
        };
        return [
            'name' => [$required, 'string', 'max:160'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[!-~]+$/D'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'quantity_type' => ['sometimes', Rule::in(['counted', 'weight'])],
            'unit' => [$required, 'string', 'max:32'],
            'buying_price' => [$required, $money],
            'selling_price' => [$required, $money],
            'reorder_level' => ['bail', 'sometimes', 'string', 'max:32', function ($attribute, $value, $fail) use ($type) {
                try { Quantity::parse($value, weighted: $type === 'weight'); }
                catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); }
            }],
            'tracks_expiry' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'department_id' => ['sometimes', 'nullable', 'string', 'regex:/^dep_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'business_id' => ['prohibited'], 'stock' => ['prohibited'], 'reorder_level_atoms' => ['prohibited'],
            'stock_total' => ['prohibited'], 'stock_available' => ['prohibited'], 'nearest_expiry' => ['prohibited'],
            'quantity_precision' => ['prohibited'], 'quantity_scale' => ['prohibited'], 'packagings' => ['prohibited'],
        ];
    }
}
