<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPinRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasRole('owner') ?? false; }
    public function rules(): array
    {
        return ['user_id' => ['required', 'string', 'regex:/^usr_[0-9A-HJKMNP-TV-Z]{26}$/D'],
            'new_pin' => ['required', 'string', 'regex:/^[0-9]{4,6}$/D']];
    }
}
