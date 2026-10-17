<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class AdminPasswordLength implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0")) {
            $fail('Use at least 12 characters and at most 72 bytes, without null characters.');
        }
    }
}
