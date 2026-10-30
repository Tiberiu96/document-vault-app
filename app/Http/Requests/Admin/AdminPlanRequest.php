<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AdminPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('platform.admin');
    }

    public function rules(): array
    {
        return ['extra_member_price_bani' => ['nullable', 'integer', 'min:0', 'max:100000000'], 'plan' => ['sometimes', 'in:free,pro'], 'enabled' => ['sometimes', 'boolean'], 'version' => ['required', 'integer', 'min:0'], 'max_pages' => ['required', 'integer', 'min:1', 'max:50'],
            'max_file_bytes' => ['required', 'integer', 'min:1024', 'max:5242880'], 'storage_bytes' => ['required', 'integer', 'min:1048576', 'max:1099511627776'],
            'monthly_ocr_pages' => ['required', 'integer', 'min:'.(app()->environment('local') ? -1 : 0), 'max:1000000'],
            'monthly_ai_calls' => ['required', 'integer', 'min:'.(app()->environment('local') ? -1 : 0), 'max:1000000']];
    }
}
