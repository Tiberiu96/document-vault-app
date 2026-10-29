<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageMembers', $this->attributes->get('workspace'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:254'], 'role' => ['required', 'in:admin,member,accountant']];
    }
}
