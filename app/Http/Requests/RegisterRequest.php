<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email', 'company_name', 'registration_number', 'county', 'city', 'street', 'postal_code'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }
        if (is_string($this->input('cui'))) {
            $this->merge(['cui' => preg_replace('/^RO/i', '', trim($this->input('cui')))]);
        }
    }

    public function rules(): array
    {
        $company = ['exclude_unless:account_type,pj', 'required', 'string'];

        return [
            'account_type' => ['required', 'in:pf,pj'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(), function ($attribute, $value, $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail(__('registration.password_long'));
                }
            }],
            'company_name' => [...$company, 'max:200'],
            // Validare locala de format; nu confirma existenta in registrul ANAF/ONRC.
            'cui' => [...$company, 'regex:/\A[1-9][0-9]{1,9}\z/'],
            'registration_number' => [...$company, 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9\/. -]{2,63}\z/'],
            'county' => [...$company, 'max:100'],
            'city' => [...$company, 'max:100'],
            'street' => [...$company, 'max:200'],
            'postal_code' => ['exclude_unless:account_type,pj', 'nullable', 'string', 'regex:/\A[0-9]{6}\z/'],
            'website' => ['nullable', 'string', 'max:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('registration.email_unavailable'),
            'cui.regex' => __('registration.cui_invalid'),
            'registration_number.regex' => __('registration.number_invalid'),
            'postal_code.regex' => __('registration.postal_invalid'),
            'password.*' => __('registration.password_requirements'),
            'website.*' => __('registration.invalid'),
        ];
    }

    public function attributes(): array
    {
        return collect(['account_type', 'name', 'email', 'password', 'company_name', 'cui', 'registration_number', 'county', 'city', 'street', 'postal_code'])
            ->mapWithKeys(fn ($key) => [$key => __('registration.'.$key)])->all();
    }
}
