<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollaborationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'allow_invites_from' => ['required', Rule::in([
                'none',
                'adminsAndGuestInviters',
                'adminsGuestInvitersAndAllMembers',
                'everyone',
            ])],
            'domain_restriction_mode' => ['required', Rule::in(['none', 'allowList', 'blockList'])],
            'allowed_domains' => ['array'],
            'allowed_domains.*' => ['string', 'max:255'],
            'blocked_domains' => ['array'],
            'blocked_domains.*' => ['string', 'max:255'],
        ];
    }
}
