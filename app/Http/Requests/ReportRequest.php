<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', Rule::in(['scam', 'prohibited', 'misleading', 'harassment', 'other'])], 'details' => ['bail', 'nullable', 'string', 'not_regex:/\x00/', 'max:2000']];
    }
}
