<?php

namespace App\Models;

use App\Enums\ReviewDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessReviewDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_review_instance_id', 'subject_type', 'subject_id',
        'decision', 'justification', 'decided_by_user_id', 'decided_at',
        'remediation_applied', 'remediation_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'decided_at' => 'datetime',
            'remediation_applied' => 'boolean',
            'remediation_applied_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AccessReviewInstance::class, 'access_review_instance_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
