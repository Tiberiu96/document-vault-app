<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AdminClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('platform.admin');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('client'))],
            'current_password' => ['required', 'current_password:admin'], 'is_super_admin' => ['prohibited'], 'password' => ['prohibited'], 'email_verified_at' => ['prohibited']];
    }
}
