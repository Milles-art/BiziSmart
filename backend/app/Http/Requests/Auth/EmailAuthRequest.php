<?php

namespace App\Http\Requests\Auth;

use App\Support\StaffPhone;
use Illuminate\Foundation\Http\FormRequest;

class EmailAuthRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) { $this->merge(['email' => strtolower(trim($this->input('email')))]); }
    }

    public function rules(): array
    {
        $method = $this->route()?->getActionMethod();
        $email = ['required', 'string', 'email:rfc', 'max:254'];
        // Bcrypt uses at most72 bytes; reject rather than silently truncating a
        // long password (including multibyte UTF-8 strings).
        $byteLimit = function ($attribute, $value, $fail) {
            if (is_string($value) && strlen($value) > 72) { $fail('The password must be at most 72 bytes. Use a shorter password.'); }
            if (is_string($value) && str_contains($value, "\0")) { $fail('The password cannot contain a null character.'); }
        };
        $password = ['required', 'string', 'min:12', 'max:72', $byteLimit, 'confirmed'];
        $token = ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'];
        $device = ['device_uid' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,128}$/D'], 'device_name' => ['required', 'string', 'max:100']];
        return match ($method) {
            'login' => ['email' => $email, 'password' => ['required', 'string', 'max:72', $byteLimit], ...$device],
            'forgot' => ['email' => $email],
            'reset' => ['token' => $token, 'password' => $password],
            'activate' => ['token' => $token, 'email' => $email, 'password' => $password],
            'enroll' => ['email' => $email, 'password' => $password, 'current_pin' => ['required', 'string', 'regex:/^[0-9]{4,6}$/D']],
            'activationLink' => ['user_id' => ['required', 'string', 'regex:/^usr_[0-9A-HJKMNP-TV-Z]{26}$/D']],
            'setup' => ['invitation_token' => $token, 'email' => $email, 'password' => $password, 'name' => ['required', 'string', 'max:100'],
                'phone' => ['sometimes', 'string', 'max:32', function ($attribute, $value, $fail) {
                    try { StaffPhone::normalize($value); } catch (\InvalidArgumentException) { $fail('Enter a Tanzania phone number.'); }
                }], ...$device],
            // Public shop registration. The shop address comes from the request host,
            // so any tenant identifier in the body is rejected outright.
            'register' => ['shop_name' => ['required', 'string', 'max:120'], 'name' => ['required', 'string', 'max:100'],
                'phone' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
                    try { StaffPhone::normalize($value); } catch (\InvalidArgumentException) { $fail('Enter a Tanzania phone number.'); }
                }], 'email' => $email, 'password' => $password, ...$device,
                'business_id' => ['prohibited'], 'subdomain' => ['prohibited']],
            default => [],
        };
    }
}
