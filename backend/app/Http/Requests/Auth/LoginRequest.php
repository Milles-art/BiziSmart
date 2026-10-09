<?php

namespace App\Http\Requests\Auth;

use App\Support\StaffPhone;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
            try { StaffPhone::normalize($value); } catch (InvalidArgumentException) { $fail('Enter a Tanzania phone number.'); }
        }], 'pin' => ['required', 'string', 'regex:/^[0-9]{4,6}$/D'],
            'device_uid' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]{8,128}$/D'], 'device_name' => ['required', 'string', 'max:100']];
    }
}
