<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkGuestActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->input('action') === 'delete') {
            return $this->user()->role->isAdmin();
        }

        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['enable', 'disable', 'delete', 'resend'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:guest_users,id'],
        ];
    }
}
