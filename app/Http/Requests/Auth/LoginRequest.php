<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordByteLimit;
use Illuminate\Contracts\Validation\ValidationRule;
use Laravel\Fortify\Fortify;

class LoginRequest extends \Laravel\Fortify\Http\Requests\LoginRequest
{
    protected function prepareForValidation(): void
    {
        // Native checkbox forms submit "on" when checked.
        if ($this->input('remember') === 'on') {
            $this->merge(['remember' => true]);
        }
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            Fortify::username() => ['bail', 'required', 'string', 'not_regex:/\x00/', 'max:255'],
            'password' => ['bail', 'required', 'string', 'not_regex:/\x00/', new PasswordByteLimit],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
