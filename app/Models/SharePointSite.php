<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SharePointSite extends Model
{
    protected $table = 'sharepoint_sites';

    protected $fillable = [
        'site_id', 'display_name', 'url', 'description',
        'sensitivity_label_id', 'external_sharing_capability',
        'storage_used_bytes', 'last_activity_at',
        'owner_display_name', 'owner_email', 'member_count',
        'raw_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'storage_used_bytes' => 'integer',
            'member_count' => 'integer',
            'raw_json' => 'array',
            'last_activity_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function sensitivityLabel(): BelongsTo
    {
        return $this->belongsTo(SensitivityLabel::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(SharePointSitePermission::class, 'sharepoint_site_id');
    }

    public function guestUsers(): HasManyThrough
    {
        return $this->hasManyThrough(
            GuestUser::class,
            SharePointSitePermission::class,
            'sharepoint_site_id',
            'id',
            'id',
            'guest_user_id'
        );
    }
}
