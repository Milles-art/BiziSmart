<?php
namespace App\Http\Requests\Messaging;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class MessagingRequest extends FormRequest {
    public function authorize():bool{return true;}
    protected function prepareForValidation():void {$this->merge(['operation_id'=>$this->header('Idempotency-Key')]);}
    public function rules():array {
        if($this->route('action')==='automation')return ['business_id'=>'prohibited','operation_id'=>'required|uuid','expected_revision'=>'required|integer|min:0','daily_summary'=>'required|boolean','debt_reminders'=>'required|boolean','at_time'=>'required|date_format:H:i','renewal_days'=>'present|nullable|integer|min:1|max:90'];
        $common=['business_id'=>'prohibited','user_id'=>'prohibited','recipient'=>'prohibited','message'=>'prohibited','operation_id'=>'required|uuid','customer_id'=>'required|string'];
        return $this->route('action')==='consent'? [...$common,'expected_revision'=>'required|integer|min:0','whatsapp'=>'required|boolean','sms'=>'required|boolean','receipts'=>'required|boolean','debt_reminders'=>'required|boolean','evidence'=>'required|string|min:3|max:500']: [...$common,'channel'=>['required',Rule::in(['whatsapp','sms'])],'sale_id'=>$this->route('action')==='receipt'?'required|string':'prohibited'];
    }
}
