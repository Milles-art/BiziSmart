<?php
namespace App\Http\Requests\Incentives;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class ConfigureIncentivesRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'loyalty_tsh_per_point'=>['present','nullable',function($a,$v,$f){try{if(Money::amount($v)<1)$f('Use a positive whole TSh earning unit.');}catch(\InvalidArgumentException|\OverflowException $e){$f($e->getMessage());}}],'loyalty_point_value'=>['sometimes','nullable',function($a,$v,$f){try{if(Money::amount($v)<1)$f('Use a positive whole TSh point value.');}catch(\InvalidArgumentException|\OverflowException $e){$f($e->getMessage());}}],'commission_basis_points'=>['present','nullable','integer','min:1','max:10000'],'reason'=>['required','string','max:1000'],'review_hash'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D'],'business_id'=>['prohibited']];}
}
