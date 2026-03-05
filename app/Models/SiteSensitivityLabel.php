<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteSensitivityLabel extends Model
{
    protected $fillable = [
        'site_id', 'site_name', 'site_url',
        'sensitivity_label_id', 'external_sharing_enabled',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'external_sharing_enabled' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function sensitivityLabel(): BelongsTo
    {
        return $this->belongsTo(SensitivityLabel::class);
    }
}
