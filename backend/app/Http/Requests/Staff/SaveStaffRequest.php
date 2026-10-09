<?php

namespace App\Http\Requests\Staff;

use App\Services\StaffRoleService;
use App\Support\StaffPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SaveStaffRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasRole('owner') ?? false; }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        return [
            'name' => [$required, 'string', 'max:100'],
            'phone' => [$required, 'string', 'max:32', function ($attribute, $value, $fail) {
                try { StaffPhone::normalize($value); } catch (InvalidArgumentException) { $fail('Enter a Tanzania phone number.'); }
            }],
            'role' => [$required, Rule::in(array_keys(StaffRoleService::ROLES))],
            'pin' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'regex:/^[0-9]{4,6}$/D'],
            'branch_id' => ['sometimes', 'nullable', 'string', 'regex:/^br_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'is_active' => [$this->isMethod('post') ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
