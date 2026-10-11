<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation ? ($this->user()?->can('send', $conversation) ?? false) : $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['bail', 'required', 'string', 'not_regex:/\x00/', 'max:2000']];
    }
}
