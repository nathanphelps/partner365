<?php

namespace App\Http\Requests;

use App\Enums\PartnerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'uuid', 'unique:partner_organizations,tenant_id'],
            'category' => ['required', Rule::enum(PartnerCategory::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mfa_trust_enabled' => ['boolean'],
            'b2b_inbound_enabled' => ['boolean'],
            'b2b_outbound_enabled' => ['boolean'],
            'device_trust_enabled' => ['boolean'],
            'direct_connect_inbound_enabled' => ['boolean'],
            'direct_connect_outbound_enabled' => ['boolean'],
            'template_id' => ['nullable', 'exists:partner_templates,id'],
        ];
    }
}
