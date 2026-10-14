<?php
namespace App\Http\Requests\Sales;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class CashSaleRequest extends FormRequest {
    protected function prepareForValidation(): void { if ($this->routeIs('sales.complete')) { $this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]); } }
    public function authorize(): bool { return $this->user()?->can('sell') ?? false; }
    public function rules(): array { return $this->checkoutRules($this->routeIs('sales.complete')); }
    public function checkoutRules(bool $commit): array {
        $money = function ($attribute,$value,$fail) { try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); } };
        return ['business_id'=>['prohibited'], 'customer_id'=>['sometimes','nullable','string','regex:/^cust_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'mobile_amount'=>['sometimes',$money], 'payment_id'=>[$commit ? 'sometimes' : 'prohibited','string','regex:/^mpay_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'loyalty_points'=>['sometimes',$money],'credit_amount'=>['sometimes',$money], 'total'=>['prohibited'], 'payments'=>['prohibited'], 'discount'=>['sometimes',$money], 'discount_reason'=>['sometimes','string','min:3','max:500'], 'approval_token'=>[$commit?'sometimes':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
            'branch_id'=>['required','string','regex:/^br_[0-9A-HJKMNP-TV-Z]{26}$/D'], 'till_id'=>['required','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'items'=>['required','array','min:1','max:100'], 'items.*'=>['array:product_id,packaging_id,qty,discount'],
            'items.*.product_id'=>['required','string','regex:/^prod_[0-9A-HJKMNP-TV-Z]{26}$/D'], 'items.*.packaging_id'=>['sometimes','nullable','string','regex:/^pkg_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'items.*.qty'=>['required','string','max:32'], 'items.*.discount'=>['sometimes',$money],
            'cash_tendered'=>[$commit ? 'required' : 'prohibited', $money],
            'review_hash'=>[$commit ? 'required' : 'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
            'idempotency_key'=>[$commit ? 'required' : 'prohibited','uuid']];
    }
}
