<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AdminCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('platform.admin');
    }

    public function rules(): array
    {
        $length = ($this->routeIs('admin.categories*') ? 'categories' : 'tags') === 'categories' ? 80 : 40;

        return [
            'system_key' => [$this->route('item') ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/\A[a-z][a-z0-9_]*\z/'],
            'scope' => [$this->route('item') || ($this->routeIs('admin.categories*') ? 'categories' : 'tags') === 'tags' ? 'prohibited' : 'required', 'in:personal,business'],
            'translations' => ['required', 'array:ro,en,hu,bg'],
            'translations.ro' => ['required', 'string', 'max:'.$length, function ($attribute, $value, $fail) {
                if (preg_match('/[ăâîșşțţĂÂÎȘŞȚŢ]/u', $value)) {
                    $fail('Traducerea romana trebuie scrisa fara diacritice.');
                }
            }],
            'translations.en' => ['required', 'string', 'max:'.$length], 'translations.hu' => ['required', 'string', 'max:'.$length], 'translations.bg' => ['required', 'string', 'max:'.$length],
        ];
    }
}
