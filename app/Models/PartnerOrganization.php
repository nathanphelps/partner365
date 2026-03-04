<?php

namespace App\Models;

use App\Enums\PartnerCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerOrganization extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'display_name', 'domain', 'category', 'owner_user_id', 'notes',
        'b2b_inbound_enabled', 'b2b_outbound_enabled', 'mfa_trust_enabled',
        'device_trust_enabled', 'direct_connect_enabled', 'raw_policy_json', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => PartnerCategory::class,
            'b2b_inbound_enabled' => 'boolean',
            'b2b_outbound_enabled' => 'boolean',
            'mfa_trust_enabled' => 'boolean',
            'device_trust_enabled' => 'boolean',
            'direct_connect_enabled' => 'boolean',
            'raw_policy_json' => 'array',
            'last_synced_at' => 'datetime',
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
}
