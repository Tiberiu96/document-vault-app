<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AdminMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) Auth::guard('admin')->user()?->is_super_admin;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:64', 'regex:/\A(?:[0-9]{6}|[A-Fa-f0-9\-\s]{32,64})\z/'], 'current_password' => ['sometimes', 'required', 'current_password:admin']];
    }
}
