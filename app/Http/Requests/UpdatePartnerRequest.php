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
            'direct_connect_enabled' => ['boolean'],
        ];
    }
}
