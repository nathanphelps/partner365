<?php

namespace App\Http\Requests;

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'review_type' => ['required', Rule::enum(ReviewType::class)],
            'scope_partner_id' => ['nullable', 'exists:partner_organizations,id'],
            'recurrence_type' => ['required', Rule::enum(RecurrenceType::class)],
            'recurrence_interval_days' => ['required_if:recurrence_type,recurring', 'nullable', 'integer', 'min:1', 'max:365'],
            'remediation_action' => ['required', Rule::enum(RemediationAction::class)],
            'reviewer_user_id' => ['required', 'exists:users,id'],
        ];
    }
}
