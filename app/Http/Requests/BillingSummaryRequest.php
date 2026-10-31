<?php

namespace App\Http\Requests;

use App\Services\Billing\BillingPriceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['nullable', Rule::in(BillingPriceService::CURRENCIES)],
            'additional_member_seats' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
