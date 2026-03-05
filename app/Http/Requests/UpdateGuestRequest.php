<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'max:255'],
            'account_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
