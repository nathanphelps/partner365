<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplySiteLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->canManage() ?? false;
    }

    public function rules(): array
    {
        return [
            'label_id' => ['required', 'string', 'max:50', 'exists:sensitivity_labels,label_id'],
        ];
    }
}
