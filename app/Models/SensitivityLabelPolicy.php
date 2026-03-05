<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensitivityLabelPolicy extends Model
{
    protected $fillable = [
        'policy_id', 'name', 'target_type', 'target_groups',
        'labels', 'raw_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'target_groups' => 'array',
            'labels' => 'array',
            'raw_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
