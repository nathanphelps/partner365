<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'redirect_url' => ['required', 'url', 'max:2048'],
            'custom_message' => ['nullable', 'string', 'max:2000'],
            'send_email' => ['boolean'],
        ];
    }
}
