<?php
namespace App\Http\Requests\Customers;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class CollectDebtRequest extends FormRequest
{
    protected function prepareForValidation(): void { if ($this->routeIs('debts.collect')) { $this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]); } }
    public function authorize(): bool { return $this->user()?->can('manage_customers') ?? false; }
    public function rules(): array {return $this->collectionRules($this->routeIs('debts.collect'));}
    public function collectionRules(bool $commit):array
    {
        $money = function ($attribute,$value,$fail) { try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); } };
        return ['payment_id'=>[$commit?'sometimes':'prohibited','string','regex:/^mpay_[0-9A-HJKMNP-TV-Z]{26}$/D'],'business_id'=>['prohibited'],'method'=>['required','in:cash,mpesa'],'amount'=>['required',$money],
            'till_id'=>['required','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'allocations'=>['required','array','min:1','max:100'],'allocations.*'=>['array:sale_id,amount'],
            'allocations.*.sale_id'=>['required','string','distinct','regex:/^sale_[0-9A-HJKMNP-TV-Z]{26}$/D'],'allocations.*.amount'=>['required',$money],
            'review_hash'=>[$commit ? 'required' : 'prohibited','string','regex:/^[a-f0-9]{64}$/D'],'idempotency_key'=>[$commit ? 'required' : 'prohibited','uuid']];
    }
}
