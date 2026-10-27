<?php

namespace App\Http\Requests;

use App\Services\PlanLimitsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy si workspace verificate in controller, inclusiv in tranzactie.
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && ! $this->filled('title') && $this->file('file') instanceof UploadedFile) {
            $name = pathinfo($this->file('file')->getClientOriginalName(), PATHINFO_FILENAME);
            $name = trim(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $name) ?? '');
            $this->merge(['title' => mb_substr($name ?: __('documents.upload'), 0, 160)]);
        }
        if (is_string($this->input('tags'))) {
            $this->merge(['tags' => array_values(array_unique(array_filter(array_map('trim', explode(',', $this->input('tags'))))))]);
        }
    }

    public function rules(): array
    {
        return [
            'ai_run_id' => ['nullable', 'uuid'],
            'metadata_version' => ['nullable', 'integer', 'min:0'],
            'analyze_with_ai' => ['nullable', 'boolean', Rule::prohibitedIf(! config('ai.enabled'))],
            'file' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'file', 'max:'.intdiv(app(PlanLimitsService::class)->free()['max_file_bytes'], 1024)],
            'title' => ['required', 'string', 'max:160'],
            'category_id' => ['nullable', 'integer'],
            'tag_ids' => ['nullable', 'array', 'max:10'],
            'tag_ids.*' => ['required', 'integer', 'distinct'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40'],
            'summary' => ['nullable', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:3000'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'amount' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/'],
            'currency' => ['nullable', 'required_with:amount', 'in:RON,EUR,HUF,BGN,USD,GBP,CHF'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return ['file.*' => __('documents.invalid_file'), 'amount.regex' => __('documents.invalid_amount'), 'tags.*.*' => __('documents.invalid_tags')];
    }

    public function attributes(): array
    {
        return collect(['file', 'title', 'category_id', 'tags', 'summary', 'description', 'supplier', 'amount', 'currency', 'issued_on', 'expires_on'])
            ->mapWithKeys(fn ($key) => [$key => __('documents.'.$key)])->all();
    }
}
