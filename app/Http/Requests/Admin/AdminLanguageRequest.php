<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AdminLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('platform.admin');
    }

    public function rules(): array
    {
        return ['locale' => [$this->route('language') ? 'prohibited' : 'required', 'string', 'regex:/\A[a-z]{2}(?:-[a-z]{2})?\z/', Rule::unique('languages', 'locale')],
            'display_name' => ['required', 'string', 'max:80'], 'native_name' => ['required', 'string', 'max:80'],
            'default_currency' => ['required', 'in:RON,EUR,HUF,BGN,USD,GBP,CHF'], 'is_active' => ['sometimes', 'boolean'], 'translation_ready' => ['sometimes', 'boolean']];
    }
}
