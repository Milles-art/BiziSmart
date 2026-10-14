<?php
namespace App\Http\Requests\Offline;
use Illuminate\Foundation\Http\FormRequest;

class OfflineReconcileRequest extends FormRequest {
    public function authorize(): bool {return true;}
    public function rules(): array {
        $review=$this->routeIs('offline.reconcile.preview');
        return ['operation_id'=>['required','uuid','lowercase'],'till_id'=>['required','string','max:64'],'reason'=>['required','string','max:1000'],
            'review_hash'=>[$review?'prohibited':'required','string','regex:/\A[a-f0-9]{64}\z/D'],
            'approval_token'=>[$review?'prohibited':'required','string','regex:/\A[a-f0-9]{64}\z/D']];
    }
}
