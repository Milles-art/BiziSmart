<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Money;
class LossAlertConfigurationRequest extends FormRequest {
    public function authorize():bool{return $this->user()?->hasRole('owner')&&$this->user()->can('view_reports');}
    public function rules():array{return ['refund_count'=>['present','nullable','integer','min:1','max:10000'],'refund_amount'=>['present','nullable','integer','min:1','max:'.Money::MAX_AMOUNT],'void_count'=>['present','nullable','integer','min:1','max:10000'],'business_id'=>['prohibited']];}
}
