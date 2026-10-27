<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TaxonomyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', [Document::class, $this->attributes->get('workspace')]);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:1', 'max:'.($this->route()->defaults['taxonomy'] === 'tag' ? 40 : 80)]];
    }

    public function attributes(): array
    {
        return ['name' => __('documents.name')];
    }
}
