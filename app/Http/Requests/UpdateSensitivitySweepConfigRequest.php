<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSensitivitySweepConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'interval_minutes' => ['required', 'integer', 'min:1'],
            'default_label_id' => ['required', 'string', 'max:50'],
            'bridge_url' => ['required', 'string', 'max:500'],
            'bridge_shared_secret' => ['required', 'string', 'max:500'],
            'rules' => ['present', 'array'],
            'rules.*.prefix' => ['required', 'string', 'min:1', 'max:100'],
            'rules.*.label_id' => ['required', 'string', 'max:50'],
            'rules.*.priority' => ['required', 'integer', 'min:1'],
            'exclusions' => ['present', 'array'],
            'exclusions.*.pattern' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
