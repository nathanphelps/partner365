<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'graph_id', 'catalog_id', 'partner_organization_id', 'display_name',
        'description', 'duration_days', 'approval_required', 'approver_user_id',
        'is_active', 'created_by_user_id', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'approval_required' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(AccessPackageCatalog::class, 'catalog_id');
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(AccessPackageResource::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccessPackageAssignment::class);
    }
}
