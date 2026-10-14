<?php
namespace App\Http\Requests\Payments;
use App\Http\Requests\Sales\CashSaleRequest;
use Illuminate\Foundation\Http\FormRequest;
class BeginPaymentRequest extends FormRequest {
    public function authorize(): bool{return $this->user()?->can('sell')??false;}
    protected function prepareForValidation(): void{$this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]);}
    public function rules(): array {
        $rules=['idempotency_key'=>['required','uuid'],'sale'=>['required_without:collection','prohibits:collection','array:branch_id,till_id,customer_id,credit_amount,mobile_amount,items,cash_tendered,review_hash,discount,discount_reason,approval_token'],
            'buyer_name'=>['required','string','max:150'],'buyer_email'=>['required','email','max:254'],
            'phone'=>['required','string','regex:/^255[67][0-9]{8}$/D'],'business_id'=>['prohibited']];
        $rules['collection']=['required_without:sale','prohibits:sale','array:customer_id,till_id,method,amount,allocations,review_hash'];
        $rules['collection.customer_id']=['required_with:collection','string','regex:/^cust_[0-9A-HJKMNP-TV-Z]{26}$/D'];
        foreach((new \App\Http\Requests\Customers\CollectDebtRequest)->collectionRules(true) as $key=>$rule){if(!in_array($key,['idempotency_key','payment_id','business_id'],true)){$rule=array_map(fn($v)=>$v==='required'?'required_with:collection':$v,$rule);$rules['collection.'.$key]=$rule;}}
        $rules['collection.method']=['required_with:collection','in:mpesa'];
        foreach((new CashSaleRequest)->checkoutRules(true) as $key=>$rule){if(!in_array($key,['idempotency_key','payment_id'],true))$rules['sale.'.$key]=array_map(fn($v)=>$v==='required'?'required_with:sale':$v,$rule);}
        $rules['sale.mobile_amount'][0]='required_with:sale';
        return $rules;
    }
}
