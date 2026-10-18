<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ActivateEmailRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['bail', 'required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }
}
