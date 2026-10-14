<?php
namespace App\Http\Requests\Incentives;
use Illuminate\Foundation\Http\FormRequest;
class LoyaltyCardRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('manage_customers')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'business_id'=>['prohibited']];}
}
