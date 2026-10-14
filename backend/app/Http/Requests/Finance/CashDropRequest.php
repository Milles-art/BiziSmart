<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Money;
class CashDropRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('manage_till')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'till_id'=>['required','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'],'receiver_id'=>['required','string','regex:/^usr_[0-9A-HJKMNP-TV-Z]{26}$/D'],'amount'=>['required','integer','min:1','max:'.Money::MAX_AMOUNT],'reason'=>['required','string','max:1000'],'review_hash'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D']];}
}
