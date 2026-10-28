<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'store') {
            return ['date' => ['nullable', 'date_format:Y-m-d', 'after:'.now(config('reminders.timezone'))->toDateString()], 'metadata_version' => ['required', 'integer', 'min:0']];
        }

        return ['action' => ['required', 'in:complete,disable,renew,snooze'], 'cycle' => ['required', 'integer', 'min:1'], 'date' => ['required_if:action,renew,snooze', 'nullable', 'date_format:Y-m-d', 'after:'.now(config('reminders.timezone'))->toDateString()]];
    }
}
