<?php

namespace App\Http\Requests;

use App\Enums\CloudEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGraphSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'cloud_environment' => ['required', 'string', Rule::enum(CloudEnvironment::class)],
            'tenant_id' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['nullable', 'string'],
            'scopes' => ['required', 'string'],
            'base_url' => ['required', 'url'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
