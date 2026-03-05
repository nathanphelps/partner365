<?php

namespace App\Http\Requests;

use App\Enums\PartnerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::enum(PartnerCategory::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'exists:users,id'],
            'mfa_trust_enabled' => ['boolean'],
            'b2b_inbound_enabled' => ['boolean'],
            'b2b_outbound_enabled' => ['boolean'],
            'device_trust_enabled' => ['boolean'],
            'direct_connect_inbound_enabled' => ['boolean'],
            'direct_connect_outbound_enabled' => ['boolean'],
            'tenant_restrictions_enabled' => ['boolean'],
            'tenant_restrictions_json' => ['nullable', 'array'],
            'tenant_restrictions_json.applications' => ['nullable', 'array'],
            'tenant_restrictions_json.applications.accessType' => ['nullable', 'string', 'in:allowed,blocked'],
            'tenant_restrictions_json.applications.targets' => ['nullable', 'array'],
            'tenant_restrictions_json.applications.targets.*.target' => ['required_with:tenant_restrictions_json.applications.targets', 'string'],
            'tenant_restrictions_json.applications.targets.*.targetType' => ['required_with:tenant_restrictions_json.applications.targets', 'string', 'in:application'],
            'tenant_restrictions_json.usersAndGroups' => ['nullable', 'array'],
            'tenant_restrictions_json.usersAndGroups.accessType' => ['nullable', 'string', 'in:allowed,blocked'],
            'tenant_restrictions_json.usersAndGroups.targets' => ['nullable', 'array'],
            'tenant_restrictions_json.usersAndGroups.targets.*.target' => ['required_with:tenant_restrictions_json.usersAndGroups.targets', 'string'],
            'tenant_restrictions_json.usersAndGroups.targets.*.targetType' => ['required_with:tenant_restrictions_json.usersAndGroups.targets', 'string', 'in:user,group'],
        ];
    }
}
