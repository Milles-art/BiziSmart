<?php
namespace App\Http\Requests\Pricing;
use Illuminate\Foundation\Http\FormRequest;
class CancelPricingRequest extends FormRequest {public function authorize():bool{return $this->user()?->hasRole('owner')??false;}public function rules():array{return ['operation_id'=>['required','uuid'],'reason'=>['required','string','max:1000'],'review_hash'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D'],'business_id'=>['prohibited']];}}
