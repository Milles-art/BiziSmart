<?php
namespace App\Http\Requests\Products;
use Illuminate\Foundation\Http\FormRequest;
class LossCountRequest extends FormRequest {
    public function authorize():bool{return $this->user()?->can('adjust_stock')??false;}
    public function rules():array{
        $commit=$this->isMethod('post')&&!str_ends_with($this->path(),'/preview');$count=str_contains($this->path(),'/counts');
        return ['operation_id'=>['required','uuid'],'batch_id'=>['required','string','regex:/^bat_[0-9A-HJKMNP-TV-Z]{26}$/D'],'qty'=>['required','string','max:32'],'reason'=>['required','string','max:500'],
            'category'=>[$count?'prohibited':'required','in:damage,spoilage,wastage'],'department_id'=>[$count?'required':'prohibited','string','regex:/^dep_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'expected_qty'=>[$commit?'required':'prohibited','string','max:32'],'approval_token'=>[$commit?'sometimes':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
            'business_id'=>['prohibited'],'approved_by'=>['prohibited'],'cost_price'=>['prohibited'],'loss_value'=>['prohibited'],'qty_change'=>['prohibited']];
    }
}
