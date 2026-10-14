<?php

namespace App\Http\Requests\Products;

use App\Support\{Money, Quantity};
use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('receive_stock') ?? false; }
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'], 'batch_number' => ['required', 'string', 'max:100'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'packaging_id' => ['sometimes', 'nullable', 'string', 'regex:/^pkg_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'qty' => ['bail', 'required', 'string', 'max:32', function ($attribute, $value, $fail) {
                try {
                    $atoms = Quantity::parse($value, weighted: ! $this->filled('packaging_id') && $this->route('product')->quantity_type === 'weight');
                    if ($atoms <= 0) { $fail('Receive a positive quantity.'); }
                } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); }
            }],
            'cost_price' => ['required', function ($attribute, $value, $fail) {
                try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); }
            }],
            'business_id' => ['prohibited'], 'product_id' => ['prohibited'], 'qty_remaining' => ['prohibited'], 'qty_remaining_atoms' => ['prohibited'],
            'received_at' => ['prohibited'], 'is_quarantined' => ['prohibited'], 'is_recalled' => ['prohibited'], 'receipt' => ['prohibited'], 'conversion_atoms' => ['prohibited'],
        ];
    }
}
