<?php
namespace App\Http\Requests\Payments;
use Illuminate\Foundation\Http\FormRequest;
class ManualRefundRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'external_reference'=>['required','string','min:3','max:150'],'reason'=>['required','string','min:3','max:500'],'refund_completed'=>['required','accepted'],
  'review_hash'=>[$this->routeIs('payments.manual_refund')?'required':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
  'approval_token'=>[$this->routeIs('payments.manual_refund')?'required':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],
  'business_id'=>['prohibited'],'amount'=>['prohibited']];}
}
