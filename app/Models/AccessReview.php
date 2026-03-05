<?php

namespace App\Models;

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'review_type', 'scope_partner_id',
        'recurrence_type', 'recurrence_interval_days', 'remediation_action',
        'reviewer_user_id', 'created_by_user_id', 'graph_definition_id',
        'next_review_at',
    ];

    protected function casts(): array
    {
        return [
            'review_type' => ReviewType::class,
            'recurrence_type' => RecurrenceType::class,
            'remediation_action' => RemediationAction::class,
            'next_review_at' => 'datetime',
        ];
    }

    public function scopedPartner(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'scope_partner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(AccessReviewInstance::class);
    }

    public function latestInstance(): HasOne
    {
        return $this->hasOne(AccessReviewInstance::class)->latestOfMany('started_at');
    }
}
