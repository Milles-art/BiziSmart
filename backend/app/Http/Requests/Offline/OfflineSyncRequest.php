<?php
namespace App\Http\Requests\Offline;
use Illuminate\Foundation\Http\FormRequest;

class OfflineSyncRequest extends FormRequest {
    public function authorize(): bool {return true;}
    public function rules(): array {
        return ['lease_id'=>['required','string','max:64'],'device_uid'=>['required','string','regex:/\A[A-Za-z0-9_-]{8,128}\z/D'],
            'operation_id'=>['required','uuid','lowercase'],'captured_at'=>['required','date_format:Y-m-d\TH:i:s.v\Z'],
            'cash_tendered'=>['required','integer','min:0','max:9007199254740991',function($attribute,$value,$fail){if(!is_int($value))$fail('Enter an exact whole TSh integer.');}],
            'items'=>['required','array','min:1','max:200'],'items.*'=>['required','array:snapshot_id,proof,qty'],
            'items.*.snapshot_id'=>['required','uuid'],'items.*.proof'=>['required','string','regex:/\A[a-f0-9]{64}\z/D'],
            'items.*.qty'=>['required','string','max:32']];
    }
}
