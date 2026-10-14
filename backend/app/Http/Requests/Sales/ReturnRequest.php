<?php
namespace App\Http\Requests\Sales;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class ReturnRequest extends FormRequest {
    protected function prepareForValidation(): void {if($this->routeIs('returns.complete'))$this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]);}
    public function authorize(): bool {return $this->user()?->can('sell')??false;}
    public function rules(): array {
        $commit=$this->routeIs('returns.complete');
        $money=function($attribute,$value,$fail){try{Money::amount($value);}catch(\InvalidArgumentException|\OverflowException $e){$fail($e->getMessage());}};
        $index=function($attribute,$value,$fail){if(!is_int($value)||$value<0||$value>99)$fail('Choose a valid original receipt line.');};
        return ['business_id'=>['prohibited'],'total'=>['prohibited'],'refund_total'=>['prohibited'],
            'operation_kind'=>['sometimes','in:return,void'],
            'mobile_refund_reference'=>['sometimes','nullable','string','min:3','max:150'],'mobile_refund_completed'=>['sometimes','boolean'],'mobile_refund'=>['prohibited'],
            'operation_id'=>['required','uuid'],'sale_id'=>['required','string','regex:/^sale_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'till_id'=>['required','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'],'reason'=>['required','string','max:500'],
            'items'=>['required','array','min:1','max:100'],'items.*'=>['array:line_index,qty,disposition'],
            'items.*.line_index'=>['required',$index],'items.*.qty'=>['required','string','max:32'],'items.*.disposition'=>['required','in:sellable,damaged'],
            'replacement'=>['sometimes','array:items,credit_amount,cash_tendered'],'replacement.items'=>['required_with:replacement','array','min:1','max:100'],
            'replacement.items.*'=>['array:product_id,qty,packaging_id'],'replacement.items.*.product_id'=>['required','string','regex:/^prod_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'replacement.items.*.qty'=>['required','string','max:32'],'replacement.items.*.packaging_id'=>['sometimes','nullable','string','regex:/^pkg_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'replacement.credit_amount'=>['sometimes',$money],'replacement.cash_tendered'=>['required_with:replacement',$money],
            'review_hash'=>[$commit?'required':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
            'approval_token'=>[$commit?'required':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
            'idempotency_key'=>[$commit?'required':'prohibited','uuid','same:operation_id']];
    }
}
