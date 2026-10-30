<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WorkspaceUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->attributes->get('workspace');

        return $workspace && $workspace->owner_id === $this->user()?->id && Gate::allows('view', $workspace);
    }

    public function rules(): array
    {
        return ['additional_member_seats' => ['sometimes', 'integer', 'min:0', 'max:1000']];
    }
}
