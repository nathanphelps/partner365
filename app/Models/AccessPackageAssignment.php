<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPackageAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'graph_id', 'access_package_id', 'target_user_email', 'target_user_id',
        'status', 'approved_by_user_id', 'expires_at', 'requested_at',
        'approved_at', 'delivered_at', 'justification', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function accessPackage(): BelongsTo
    {
        return $this->belongsTo(AccessPackage::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
