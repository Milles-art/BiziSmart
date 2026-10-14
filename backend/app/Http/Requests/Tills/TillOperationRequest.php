<?php
namespace App\Http\Requests\Tills;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Money;
class TillOperationRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->can('manage_till') ?? false; }
    public function rules(): array {
        $money=function ($attribute,$value,$fail) { try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); } };
        $signed=function ($attribute,$value,$fail) { if (! is_int($value) || $value > Money::MAX_AMOUNT || $value < -Money::MAX_AMOUNT) { $fail('Use an exact integer TSh amount within the supported bounds.'); } };
        $rules=['operation_id'=>['required','uuid'],'business_id'=>['prohibited'],'cashier_id'=>['prohibited'],'approved_by'=>['prohibited'],'payload_hash'=>['prohibited'],'status'=>['prohibited'],'cash_sales'=>['prohibited'],'cash_refunds'=>['prohibited'],'cash_debt_payments'=>['prohibited'],'cash_drop_total'=>['prohibited'],'paid_out_total'=>['prohibited']];
        if ($this->routeIs('tills.open')) { return [...$rules,'branch_id'=>['required','string','regex:/^br_[0-9A-HJKMNP-TV-Z]{26}$/D'],'opening_float'=>['required',$money]]; }
        $rules['till_id']=['required','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'];
        if ($this->routeIs('tills.paid_out')) { return [...$rules,'amount'=>['required',$money],'reason'=>['required','string','max:500']]; }
        $rules['counted_cash']=['required',$money];
        return [...$rules,'expected_cash'=>[$this->routeIs('tills.close') ? 'required' : 'prohibited',$signed],
            'revision'=>[$this->routeIs('tills.close') ? 'required' : 'prohibited',$money],
            'variance_limit'=>[$this->routeIs('tills.close') ? 'required' : 'prohibited',$money],
            'approval_token'=>[$this->routeIs('tills.close') ? 'sometimes' : 'prohibited','nullable','string','regex:/^[a-f0-9]{64}$/D']];
    }
}
