<?php
namespace App\Http\Requests\Incentives;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class CommissionPayoutRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'cashier_id'=>['required','string','regex:/^usr_[0-9A-HJKMNP-TV-Z]{26}$/D'],'amount'=>['required',function($a,$v,$f){try{if(Money::amount($v)<1)$f('Use a positive whole TSh amount.');}catch(\InvalidArgumentException|\OverflowException $e){$f($e->getMessage());}}],'paid_on'=>['required','date_format:Y-m-d'],'external_reference'=>['required','string','max:160'],'external_payment_completed'=>['required','boolean','accepted'],'reason'=>['required','string','max:1000'],'review_hash'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D'],'business_id'=>['prohibited']];}
}
