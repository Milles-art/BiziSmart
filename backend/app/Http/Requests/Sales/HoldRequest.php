<?php
namespace App\Http\Requests\Sales;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class HoldRequest extends FormRequest {
    protected function prepareForValidation(): void { $this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]); }
    public function authorize(): bool { return $this->user()?->can('sell')??false; }
    public function rules(): array {
        $money=function($attribute,$value,$fail) { try { Money::amount($value); } catch(\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); } };
        $rules=['idempotency_key'=>['required','uuid'],'business_id'=>['prohibited'],'till_id'=>['prohibited'],'approval_token'=>['prohibited'],'cash_tendered'=>['prohibited'],'payment_id'=>['prohibited']];
        if (!$this->routeIs('holds.save')) return $rules;
        return [...$rules,'branch_id'=>['required','string','regex:/^br_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'items'=>['required','array','min:1','max:100'],'items.*'=>['array:product_id,packaging_id,qty,discount'],
            'items.*.product_id'=>['required','string','regex:/^prod_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'items.*.packaging_id'=>['sometimes','nullable','string','regex:/^pkg_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'items.*.qty'=>['required','string','max:32'],'items.*.discount'=>['sometimes',$money],
            'customer_id'=>['sometimes','nullable','string','regex:/^cust_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'credit_amount'=>['sometimes',$money],'discount'=>['sometimes',$money],'discount_reason'=>['sometimes','nullable','string','max:500']];
    }
}
