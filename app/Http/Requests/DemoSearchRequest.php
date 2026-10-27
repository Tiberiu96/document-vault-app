<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DemoSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:all,bills,contracts,insurance'],
        ];
    }
}
