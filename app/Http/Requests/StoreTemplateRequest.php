<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'policy_config' => ['required', 'array'],
            'policy_config.b2b_inbound_enabled' => ['boolean'],
            'policy_config.b2b_outbound_enabled' => ['boolean'],
            'policy_config.mfa_trust_enabled' => ['boolean'],
            'policy_config.device_trust_enabled' => ['boolean'],
            'policy_config.direct_connect_enabled' => ['boolean'],
        ];
    }
}
