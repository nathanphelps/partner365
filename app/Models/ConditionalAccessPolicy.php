<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ConditionalAccessPolicy extends Model
{
    protected $fillable = [
        'policy_id', 'display_name', 'state',
        'guest_or_external_user_types', 'external_tenant_scope', 'external_tenant_ids',
        'target_applications', 'grant_controls', 'session_controls',
        'raw_policy_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'external_tenant_ids' => 'array',
            'grant_controls' => 'array',
            'session_controls' => 'array',
            'raw_policy_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(PartnerOrganization::class, 'conditional_access_policy_partner')
            ->withPivot('matched_user_type')
            ->withTimestamps();
    }
}
