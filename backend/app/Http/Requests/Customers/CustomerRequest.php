<?php
namespace App\Http\Requests\Customers;
use App\Support\{Money,StaffPhone};
use Illuminate\Foundation\Http\FormRequest;
class CustomerRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage_customers') ?? false; }
    public function rules(): array
    {
        $money = function ($attribute,$value,$fail) { try { Money::amount($value); } catch (\InvalidArgumentException|\OverflowException $e) { $fail($e->getMessage()); } };
        $phone = function ($attribute,$value,$fail) { try { StaffPhone::normalize($value); } catch (\InvalidArgumentException $e) { $fail($e->getMessage()); } };
        if ($this->isMethod('get')) { return ['search'=>['sometimes','nullable','string','max:100'],'page'=>['sometimes','integer','min:1','max:1000000'],'per_page'=>['sometimes','integer','min:1','max:100'],'business_id'=>['prohibited']]; }
        return ['name'=>[$this->isMethod('post') ? 'required' : 'sometimes','string','min:1','max:120'],
            'phone'=>['sometimes','nullable','string','max:30',$phone],'credit_limit'=>[$this->isMethod('post') ? 'required' : 'sometimes',$money],
            'business_id'=>['prohibited'],'outstanding_balance'=>['prohibited']];
    }
}
