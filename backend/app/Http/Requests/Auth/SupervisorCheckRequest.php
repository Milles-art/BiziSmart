<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;
use App\Services\SupervisorApprovalService;

class SupervisorCheckRequest extends LoginRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['device_uid'], $rules['device_name']);
        if ($this->has('email') || $this->has('password')) {
            unset($rules['phone'], $rules['pin']);
            $rules['email'] = ['required', 'string', 'email:rfc', 'max:254'];
            $rules['password'] = ['required', 'string', 'max:72', function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) { $fail('Passwords must use at most 72 bytes.'); }
                if (is_string($value) && str_contains($value, "\0")) { $fail('The password cannot contain a null character.'); }
            }];
            $rules['phone'] = ['prohibited'];
            $rules['pin'] = ['prohibited'];
        }
        return [...$rules, 'permission' => ['required', Rule::in(SupervisorApprovalService::PERMISSIONS)],
            'operation_id' => ['required', 'uuid'], 'payload_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'code' => ['sometimes', 'string', 'regex:/^[0-9]{6}$/D']];
    }
}
