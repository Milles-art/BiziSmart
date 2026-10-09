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
        return [...$rules, 'permission' => ['required', Rule::in(SupervisorApprovalService::PERMISSIONS)],
            'operation_id' => ['required', 'uuid'], 'payload_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'code' => ['sometimes', 'string', 'regex:/^[0-9]{6}$/D']];
    }
}
