<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Money;
class BankActionRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array {
  $money=function($a,$v,$fail){try{if(Money::amount($v)<1)$fail('Use a positive integer TSh amount.');}catch(\Throwable){$fail('Use a positive integer TSh amount.');}};
  $rules=['business_id'=>['prohibited'],'operation_id'=>['required','uuid']];
  return match($this->route('action')) {
   'statement'=>[...$rules,'account'=>['required','string','max:100'],'booked_on'=>['required','date_format:Y-m-d'],'direction'=>['required','in:debit,credit'],'amount'=>['required',$money],'reference'=>['required','string','min:2','max:150']],
   'match'=>[...$rules,'line_id'=>['required','string'],'payment_type'=>['required','in:supplier,expense'],'payment_id'=>['required','string']],
   'schedule'=>[...$rules,'invoice_id'=>['required','string'],'due_on'=>['required','date_format:Y-m-d'],'amount'=>['required',$money]],
   'cancel'=>[...$rules,'schedule_id'=>['required','string']],
   default=>['action'=>['required','prohibited']],
  };
 }
}
