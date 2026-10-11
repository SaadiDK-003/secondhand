<?php

namespace App\Concerns;

use App\Rules\PasswordByteLimit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['bail', 'required', 'string', 'not_regex:/\x00/', new PasswordByteLimit, Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['bail', 'required', 'string', 'not_regex:/\x00/', new PasswordByteLimit, 'current_password'];
    }
}
