<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['prohibited'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096', 'prohibited_if:remove_avatar,1'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['avatar.*' => __('profile.avatar_rules'), 'email.prohibited' => __('profile.email_locked')];
    }

    public function attributes(): array
    {
        return ['name' => __('profile.name'), 'avatar' => __('profile.avatar')];
    }
}
