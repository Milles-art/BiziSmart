<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\Money;
class ExpensePaymentRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'kind'=>['required',Rule::in(['payment','refund'])],'source'=>['required',Rule::in(['till','bank','mobile'])],'amount'=>['required','integer','min:1','max:'.Money::MAX_AMOUNT],'paid_on'=>['required','date_format:Y-m-d','before_or_equal:'.now('Africa/Dar_es_Salaam')->format('Y-m-d')],'reference'=>['sometimes','nullable','string','max:120'],'till_id'=>['sometimes','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'],'payment_id'=>['sometimes','string','regex:/^epay_[0-9A-HJKMNP-TV-Z]{26}$/D'],'review_hash'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D'],'approval_token'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D']];}
}
