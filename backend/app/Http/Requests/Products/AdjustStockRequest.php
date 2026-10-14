<?php
namespace App\Http\Requests\Products;
use Illuminate\Foundation\Http\FormRequest;
class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('adjust_stock') ?? false; }
    public function rules(): array {
        return ['operation_id' => ['required', 'uuid'], 'batch_id' => ['required', 'string', 'regex:/^bat_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'qty_change' => ['required', 'string', 'max:32'], 'reason' => ['required', 'string', 'max:500'],
            'expected_qty' => [$this->routeIs('stock.adjust') ? 'required' : 'prohibited', 'string', 'max:32'],
            'approval_token' => [$this->routeIs('stock.adjust') ? 'sometimes' : 'prohibited', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'business_id' => ['prohibited'], 'payload_hash' => ['prohibited'], 'qty_after' => ['prohibited'], 'approved_by' => ['prohibited'], 'cost_price' => ['prohibited'], 'packaging_id' => ['prohibited']];
    }
}
