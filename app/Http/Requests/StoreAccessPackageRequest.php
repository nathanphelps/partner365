<?php

namespace App\Http\Requests;

use App\Enums\AccessPackageResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'partner_organization_id' => ['required', 'exists:partner_organizations,id'],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'approval_required' => ['required', 'boolean'],
            'approver_user_id' => ['required_if:approval_required,true', 'nullable', 'exists:users,id'],
            'resources' => ['required', 'array', 'min:1'],
            'resources.*.resource_type' => ['required', Rule::enum(AccessPackageResourceType::class)],
            'resources.*.resource_id' => ['required', 'string'],
            'resources.*.resource_display_name' => ['required', 'string', 'max:255'],
        ];
    }
}
