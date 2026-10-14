<?php
namespace App\Http\Requests\StaffOperations;
use Illuminate\Foundation\Http\FormRequest;
class StaffOperationRequest extends FormRequest {
    protected function prepareForValidation():void {$this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]);}
    public function authorize():bool{return $this->user()!==null;}
    public function rules():array {
        $rules=['idempotency_key'=>['required','uuid'],'business_id'=>['prohibited'],'user_id'=>['prohibited'],'device_id'=>['prohibited'],'started_at'=>['prohibited'],'ended_at'=>['prohibited']];
        return match($this->route('action')){
            'correction'=>[...$rules,'attendance_id'=>['required','string','regex:/^att_[0-9A-HJKMNP-TV-Z]{26}$/D'],'expected_revision'=>['required','integer','min:0'],'corrected_started_at'=>['required','date_format:Y-m-d\TH:i'],'corrected_ended_at'=>['sometimes','nullable','date_format:Y-m-d\TH:i'],'reason'=>['required','string','min:3','max:500']],
            'preferences'=>[...$rules,'expected_revision'=>['required','integer','min:0'],'in_app'=>['required','boolean'],'whatsapp'=>['required','boolean'],'sms'=>['required','boolean']],
            'read_notification'=>[...$rules,'notification_id'=>['required','string','regex:/^notice_[0-9A-HJKMNP-TV-Z]{26}$/D']],
            default=>$rules,
        };
    }
}
