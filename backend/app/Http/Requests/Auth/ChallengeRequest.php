<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ChallengeRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['challenge_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            ...($this->routeIs('auth.pin.change') ? ['new_pin' => ['required', 'string', 'regex:/^[0-9]{4,6}$/D']]
                : ['code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']])];
    }
}
