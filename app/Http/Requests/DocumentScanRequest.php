<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Services\PlanLimitsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DocumentScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [Document::class, $this->attributes->get('workspace')]);
    }

    public function rules(): array
    {
        $limits = app(PlanLimitsService::class)->forWorkspace($this->attributes->get('workspace'));

        return [
            'retain_file' => ['nullable', 'boolean'],
            'pages' => ['required', 'array', 'min:1', 'max:'.$limits['max_pages']],
            'pages.*' => ['required', 'file', 'mimes:jpg,jpeg,png', 'dimensions:max_width=2500,max_height=2500', 'max:'.intdiv($limits['max_file_bytes'], 1024)],
            'analyze_with_ai' => ['nullable', 'boolean', Rule::prohibitedIf(! config('ai.enabled'))],
        ];
    }

    public function messages(): array
    {
        return ['pages.*' => __('scanner.invalid'), 'pages.max' => __('scanner.limit'), 'pages.*.*' => __('scanner.invalid')];
    }
}
