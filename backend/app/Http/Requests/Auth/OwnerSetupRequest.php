<?php

namespace App\Http\Requests\Auth;

class OwnerSetupRequest extends LoginRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'name' => ['required', 'string', 'max:100'],
            'invitation_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }
}
