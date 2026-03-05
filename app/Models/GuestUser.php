<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'entra_user_id', 'email', 'display_name', 'user_principal_name',
        'partner_organization_id', 'invitation_status', 'invited_by_user_id',
        'invited_at', 'last_sign_in_at', 'account_enabled', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'invitation_status' => InvitationStatus::class,
            'invited_at' => 'datetime',
            'last_sign_in_at' => 'datetime',
            'account_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function sharePointSitePermissions(): HasMany
    {
        return $this->hasMany(SharePointSitePermission::class, 'guest_user_id');
    }
}
