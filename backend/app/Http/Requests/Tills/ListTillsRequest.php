<?php
namespace App\Http\Requests\Tills;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class ListTillsRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->can('manage_till') ?? false; }
    public function rules(): array { return ['status'=>['sometimes',Rule::in(['open','closed'])],'page'=>['sometimes','integer','min:1','max:100000'],'per_page'=>['sometimes','integer','min:1','max:50'],'business_id'=>['prohibited']]; }
}
