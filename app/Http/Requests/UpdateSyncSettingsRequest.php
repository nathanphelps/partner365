<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSyncSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'partners_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'guests_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
