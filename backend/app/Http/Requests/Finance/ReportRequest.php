<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use App\Support\ReportPeriod;
class ReportRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')&&$this->user()->can('view_reports');}
 public function rules():array{return ['from'=>['required','date_format:Y-m-d'],'to'=>['required','date_format:Y-m-d'],'page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'branch_id'=>['sometimes','string','regex:/^br_[0-9A-HJKMNP-TV-Z]{26}$/D']];}
 protected function passedValidation():void{try{new ReportPeriod($this->validated('from'),$this->validated('to'));}catch(\InvalidArgumentException $e){throw ValidationException::withMessages(['from'=>$e->getMessage()]);}}
}
