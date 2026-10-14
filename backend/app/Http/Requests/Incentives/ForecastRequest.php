<?php
namespace App\Http\Requests\Incentives;
use Illuminate\Foundation\Http\FormRequest;
class ForecastRequest extends FormRequest {public function authorize():bool{return $this->user()?->hasRole('owner')??false;}public function rules():array{return ['from'=>['required','date_format:Y-m-d'],'to'=>['required','date_format:Y-m-d','after_or_equal:from'],'coverage_days'=>['required','integer','min:1','max:90'],'page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'search'=>['sometimes','string','max:160']];}}
