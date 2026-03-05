<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SensitivityLabel extends Model
{
    protected $fillable = [
        'label_id', 'name', 'description', 'color', 'tooltip',
        'scope', 'priority', 'is_active', 'parent_label_id',
        'protection_type', 'raw_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'is_active' => 'boolean',
            'raw_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SensitivityLabel::class, 'parent_label_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SensitivityLabel::class, 'parent_label_id');
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(PartnerOrganization::class, 'sensitivity_label_partner')
            ->withPivot('matched_via', 'policy_name', 'site_name')
            ->withTimestamps();
    }
}
