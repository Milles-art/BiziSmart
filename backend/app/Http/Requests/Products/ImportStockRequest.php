<?php
namespace App\Http\Requests\Products;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class ImportStockRequest extends FormRequest
{
    public function authorize(): bool { return ($this->user()?->can('receive_stock') ?? false) && ($this->user()?->can('view_buying_price') ?? false); }
    public function rules(): array
    {
        return ['operation_id' => ['required', 'uuid'], 'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*' => ['required', 'array:barcode,batch_number,qty,cost_price,expiry_date'],
            'rows.*.barcode' => ['required', 'string', 'max:100', 'regex:/^[!-~]+$/D'],
            'rows.*.batch_number' => ['required', 'string', 'max:100'], 'rows.*.qty' => ['required', 'string', 'max:32'],
            'rows.*.cost_price' => ['required', function ($attribute, $value, $fail) { try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); } }],
            'rows.*.expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_hash' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'business_id' => ['prohibited']];
    }
}
