<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharePointSitePermission extends Model
{
    protected $table = 'sharepoint_site_permissions';

    protected $fillable = [
        'sharepoint_site_id', 'guest_user_id', 'role', 'granted_via',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(SharePointSite::class, 'sharepoint_site_id');
    }

    public function guestUser(): BelongsTo
    {
        return $this->belongsTo(GuestUser::class, 'guest_user_id');
    }
}
