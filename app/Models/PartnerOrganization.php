<?php

namespace App\Models;

use App\Enums\PartnerCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerOrganization extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'display_name', 'domain', 'favicon_path', 'category', 'owner_user_id', 'notes',
        'b2b_inbound_enabled', 'b2b_outbound_enabled', 'mfa_trust_enabled',
        'device_trust_enabled', 'direct_connect_inbound_enabled', 'direct_connect_outbound_enabled',
        'tenant_restrictions_enabled', 'tenant_restrictions_json',
        'raw_policy_json', 'last_synced_at',
        'trust_score', 'trust_score_breakdown', 'trust_score_calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => PartnerCategory::class,
            'b2b_inbound_enabled' => 'boolean',
            'b2b_outbound_enabled' => 'boolean',
            'mfa_trust_enabled' => 'boolean',
            'device_trust_enabled' => 'boolean',
            'direct_connect_inbound_enabled' => 'boolean',
            'direct_connect_outbound_enabled' => 'boolean',
            'tenant_restrictions_enabled' => 'boolean',
            'tenant_restrictions_json' => 'array',
            'raw_policy_json' => 'array',
            'last_synced_at' => 'datetime',
            'trust_score_breakdown' => 'array',
            'trust_score_calculated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function guestUsers(): HasMany
    {
        return $this->hasMany(GuestUser::class, 'partner_organization_id');
    }

    public function conditionalAccessPolicies(): BelongsToMany
    {
        return $this->belongsToMany(ConditionalAccessPolicy::class, 'conditional_access_policy_partner')
            ->withPivot('matched_user_type')
            ->withTimestamps();
    }

    public function sensitivityLabels(): BelongsToMany
    {
        return $this->belongsToMany(SensitivityLabel::class, 'sensitivity_label_partner')
            ->withPivot('matched_via', 'policy_name', 'site_name')
            ->withTimestamps();
    }
}
