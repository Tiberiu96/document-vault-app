<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateProfilePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:1024', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols(), function ($attribute, $value, $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail(__('registration.password_long'));
                }
            }],
            'email' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['password.*' => __('account_mail.password_rules')];
    }

    public function attributes(): array
    {
        return ['current_password' => __('profile.current_password'), 'password' => __('profile.new_password')];
    }
}
