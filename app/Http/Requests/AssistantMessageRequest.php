<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistantMessageRequest extends FormRequest
{
    public function rules(): array
    {
        $maximum = (int) config('assistant.max_history_messages');
        $characters = (int) config('assistant.max_message_characters');

        return [
            'messages' => ['required', 'array', 'min:1', 'max:'.$maximum],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:'.$characters],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $messages = $this->input('messages', []);
            if (! is_array($messages) || ($messages[array_key_last($messages)]['role'] ?? null) !== 'user') {
                $validator->errors()->add('messages', __('assistant.invalid_message'));
            }
        }];
    }
}
