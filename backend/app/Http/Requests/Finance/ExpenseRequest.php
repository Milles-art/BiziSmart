<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Money;
class ExpenseRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'review_hash'=>[$this->is('api/v1/expenses/preview')?'prohibited':'required','string','regex:/^[a-f0-9]{64}$/D'],'business_id'=>['prohibited'],'amount'=>['required',function($a,$v,$f){try{if(Money::amount($v)<1)$f('Use a positive whole TSh amount.');}catch(\InvalidArgumentException|\OverflowException $e){$f($e->getMessage());}}],'category'=>['required','string','max:80'],'description'=>['required','string','max:1000'],'incurred_on'=>['required','date_format:Y-m-d','before_or_equal:'.now('Africa/Dar_es_Salaam')->format('Y-m-d')]];}
}
