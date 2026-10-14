<?php
namespace App\Http\Requests\Incentives;
use Illuminate\Foundation\Http\FormRequest;
class ScaleProfileRequest extends FormRequest {
 public function authorize():bool{return ($this->user()?->hasRole('owner')??false)&&$this->user()->can('manage_settings');}
 public function rules():array{return ['operation_id'=>['required','uuid'],'profile'=>['present','nullable','array:prefix,product_start,product_length,weight_start,weight_length','size:5'],'profile.prefix'=>['required_with:profile','string','regex:/^[0-9]{1,4}$/D'],'profile.product_start'=>['required_with:profile','integer','min:1','max:11'],'profile.product_length'=>['required_with:profile','integer','min:1','max:10'],'profile.weight_start'=>['required_with:profile','integer','min:1','max:11'],'profile.weight_length'=>['required_with:profile','integer','min:1','max:7'],'business_id'=>['prohibited']];}
}
