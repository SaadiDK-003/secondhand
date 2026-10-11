<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordByteLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt silently truncates passwords after 72 bytes, including multibyte text.
        if (is_string($value) && strlen($value) > 72) {
            $fail('Use a password with at most 72 bytes.');
        }
    }
}
