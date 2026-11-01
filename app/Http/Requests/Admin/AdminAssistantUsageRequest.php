<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminAssistantUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'workspace_id' => ['nullable', 'integer', 'min:1', 'exists:workspaces,id'],
        ];
    }
}
