<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSsoSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'auto_approve' => ['required', 'boolean'],
            'default_role' => ['required', 'string', Rule::enum(UserRole::class)],
            'group_mapping_enabled' => ['required', 'boolean'],
            'group_mappings' => ['present', 'array'],
            'group_mappings.*.entra_group_id' => ['required_if:group_mapping_enabled,true', 'nullable', 'string', 'max:255'],
            'group_mappings.*.entra_group_name' => ['nullable', 'string', 'max:255'],
            'group_mappings.*.role' => ['required_if:group_mapping_enabled,true', 'nullable', 'string', Rule::enum(UserRole::class)],
            'restrict_provisioning_to_mapped_groups' => ['required', 'boolean'],
        ];
    }
}
