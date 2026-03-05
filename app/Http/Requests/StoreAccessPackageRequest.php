<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'approver_user_id' => ['nullable', 'exists:users,id'],
            'resources' => ['required', 'array', 'min:1'],
            'resources.*.resource_type' => ['required', 'in:group,sharepoint_site'],
            'resources.*.resource_id' => ['required', 'string'],
            'resources.*.resource_display_name' => ['required', 'string', 'max:255'],
        ];
    }
}
